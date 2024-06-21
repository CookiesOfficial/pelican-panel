<?php

namespace App\Console\Commands\Environment;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use App\Traits\Commands\EnvironmentWriterTrait;

class DatabaseSettingsCommand extends Command
{
    use EnvironmentWriterTrait;

    public const DATABASE_DRIVERS = [
        'sqlite' => 'SQLite (recommended)',
        'mariadb' => 'MariaDB',
        'mysql' => 'MySQL',
        'pgsql' => 'PostgreSQL',
    ];

    protected $description = 'Configure database settings for the Panel.';

    protected $signature = 'p:environment:database
                            {--driver= : The database driver backend to use.}
                            {--database= : The database to use.}
                            {--host= : The connection address for the MySQL/MariaDB/PostgreSQL server.}
                            {--port= : The connection port for the MySQL/MariaDB/PostgreSQL server.}
                            {--username= : Username to use when connecting to the MySQL/MariaDB/PostgreSQL server.}
                            {--password= : Password to use for the MySQL/MariaDB/PostgreSQL database.}';

    protected array $variables = [];

    /**
     * DatabaseSettingsCommand constructor.
     */
    public function __construct(private DatabaseManager $database, private Kernel $console)
    {
        parent::__construct();
    }

    /**
     * Handle command execution.
     */
    public function handle(): int
    {
        $selected = config('database.default', 'sqlite');
        $this->variables['DB_CONNECTION'] = $this->option('driver') ?? $this->choice(
            'Database Driver',
            self::DATABASE_DRIVERS,
            array_key_exists($selected, self::DATABASE_DRIVERS) ? $selected : null
        );

        if (in_array($this->variables['DB_CONNECTION'], ['mysql', 'mariadb', 'pgsql'])) {
            $this->output->note(__('commands.database_settings.DB_HOST_note'));
            $this->variables['DB_HOST'] = $this->option('host') ?? $this->ask(
                'Database Host',
                config('database.connections.' . $this->variables['DB_CONNECTION'] . '.host', '127.0.0.1')
            );

            $this->variables['DB_PORT'] = $this->option('port') ?? $this->ask(
                'Database Port',
                config('database.connections.' . $this->variables['DB_CONNECTION'] . '.port', $this->variables['DB_CONNECTION'] === 'pgsql' ? 5432 : 3306)
            );

            $this->variables['DB_DATABASE'] = $this->option('database') ?? $this->ask(
                'Database Name',
                config('database.connections.' . $this->variables['DB_CONNECTION'] . '.database', 'panel')
            );

            $this->output->note(__('commands.database_settings.DB_USERNAME_note'));
            $this->variables['DB_USERNAME'] = $this->option('username') ?? $this->ask(
                'Database Username',
                config('database.connections.' . $this->variables['DB_CONNECTION'] . '.username', 'pelican')
            );

            $askForDBPassword = true;
            if (!empty(config('database.connections.' . $this->variables['DB_CONNECTION'] . '.password')) && $this->input->isInteractive()) {
                $this->variables['DB_PASSWORD'] = config('database.connections.' . $this->variables['DB_CONNECTION'] . '.password');
                $askForDBPassword = $this->confirm(__('commands.database_settings.DB_PASSWORD_note'));
            }

            if ($askForDBPassword) {
                $this->variables['DB_PASSWORD'] = $this->option('password') ?? $this->secret('Database Password');
            }

            try {
                // Test connection
                config()->set('database.connections._panel_command_test', [
                    'driver' => $this->variables['DB_CONNECTION'],
                    'host' => $this->variables['DB_HOST'],
                    'port' => $this->variables['DB_PORT'],
                    'database' => $this->variables['DB_DATABASE'],
                    'username' => $this->variables['DB_USERNAME'],
                    'password' => $this->variables['DB_PASSWORD'],
                    'charset' => 'utf8',
                    'prefix' => '',
                    'schema' => $this->variables['DB_CONNECTION'] === 'pgsql' ? 'public' : null,
                    'sslmode' => $this->variables['DB_CONNECTION'] === 'pgsql' ? 'prefer' : null,
                ]);

                $this->database->connection('_panel_command_test')->getPdo();
            } catch (\PDOException $exception) {
                $this->output->error(sprintf('Unable to connect to the %s server using the provided credentials. The error returned was "%s".', ucfirst($this->variables['DB_CONNECTION']), $exception->getMessage()));
                $this->output->error(__('commands.database_settings.DB_error_2'));

                if ($this->confirm(__('commands.database_settings.go_back'))) {
                    $this->database->disconnect('_panel_command_test');

                    return $this->handle();
                }

                return 1;
            }
        } elseif ($this->variables['DB_CONNECTION'] === 'sqlite') {
            $this->variables['DB_DATABASE'] = $this->option('database') ?? $this->ask(
                'Database Path',
                env('DB_DATABASE', 'database.sqlite')
            );
        }

        $this->writeToEnvironment($this->variables);

        $this->info($this->console->output());

        return 0;
    }
}
