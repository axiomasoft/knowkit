<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Chatom\Actors\Data\ActorRef;
use Chatom\Authorization\Contracts\ChannelAccess;
use Chatom\Channels\Contracts\Channels;
use Chatom\Channels\Data\CreateChannel;
use Chatom\ChatomServiceProvider;
use Chatom\Documents\Data\MessageDocument;
use Chatom\Messages\Contracts\Messages;
use Chatom\Messages\Data\SendMessage;
use Chatom\Messages\Data\SendMessageResult;
use Chatom\Plugins\Plugins;
use Chatom\Schema\Contracts\SchemaSpaceResolver;
use Chatom\Schema\Testing\SchemaTesting;
use Chatom\Spaces\Data\SpaceId;
use Illuminate\Config\Repository;
use Illuminate\Console\Application as Console;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use KnowKit\Chatom\ChatomReplyBoundary;
use KnowKit\Tests\Answer\Contracts\Fixture;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/** Local disposable Laravel host; no HTTP listener, remote provider, or external host. */
final class NativeHost
{
    public Application $app;
    public Connection $db;
    public InstalledHandler $handler;
    public string $schema;
    public string $directory;
    public int $major;
    private PDO $admin;

    public function __construct(int $major)
    {
        RuntimePins::requireInstalledProfile();
        $this->major = $major;
        $matrix = self::matrix();
        $port = (int) (getenv('CHATOM_TEST_PG' . $major . '_PORT') ?: $matrix['postgres'][(string) $major]['port']);
        $this->admin = new PDO(
            dsn: "pgsql:host=127.0.0.1;port={$port};dbname=knowkit_chatom_test",
            username: 'knowkit_test',
            options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $observed = (int) $this->admin->query('SHOW server_version_num')->fetchColumn();
        if (intdiv($observed, 10000) !== $major) {
            throw new RuntimeException('Qualification must not substitute a PostgreSQL version');
        }
        if ($this->admin->query('SHOW server_version')->fetchColumn() !== $matrix['postgres'][(string) $major]['serverVersion']) {
            throw new RuntimeException('Exact pinned PostgreSQL runtime mismatch');
        }
        $this->schema = 'knowkit_qa_' . bin2hex(random_bytes(12));
        $this->directory = sys_get_temp_dir() . '/knowkit-chatom-' . bin2hex(random_bytes(12));
        $this->app = new Application($this->directory);
        $this->admin->exec('CREATE SCHEMA ' . $this->schema);
        try {
            $this->boot($port);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public static function matrix(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/v1/matrix.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    private function boot(int $port): void
    {
        foreach (['bootstrap/cache', 'storage/framework/views', 'storage/logs', 'database/migrations'] as $path) {
            mkdir(directory: $this->directory . '/' . $path, permissions: 0700, recursive: true);
        }
        $this->app->instance('env', 'testing');
        $this->app->instance('config', new Repository($this->configuration($port)));
        $this->app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, \Illuminate\Foundation\Exceptions\Handler::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        foreach ([
            \Illuminate\Events\EventServiceProvider::class,
            \Illuminate\Filesystem\FilesystemServiceProvider::class,
            \Illuminate\Log\LogServiceProvider::class,
            \Illuminate\Cache\CacheServiceProvider::class,
            \Illuminate\Database\DatabaseServiceProvider::class,
            \Illuminate\Database\MigrationServiceProvider::class,
            \Illuminate\Bus\BusServiceProvider::class,
            \Illuminate\Queue\QueueServiceProvider::class,
            \Illuminate\Routing\RoutingServiceProvider::class,
            \Illuminate\Auth\AuthServiceProvider::class,
            ChatomServiceProvider::class,
        ] as $provider) {
            $this->app->register($provider);
        }
        Plugins::add(app: $this->app, plugins: HostPlugin::class);
        $native = $this->app->make(ChannelAccess::class);
        $boundary = new ChatomReplyBoundary($native);
        $this->app->instance(ChannelAccess::class, $boundary);
        $this->app->instance(ChatomReplyBoundary::class, $boundary);
        $this->app->singleton(InstalledHandler::class);
        $this->app->boot();
        $this->db = $this->app->make('db')->connection();
        $target = $this->app->make(SchemaSpaceResolver::class)->resolve();
        SchemaTesting::migrate(
            target: $target,
            configuration: $this->app['config']->get('database.connections.qualification'),
            excluded: [],
        );
        $this->handler = $this->app->make(InstalledHandler::class);
    }

    private function configuration(int $port): array
    {
        return [
            'app' => ['env' => 'testing', 'key' => str_repeat('q', 32), 'debug' => false],
            'auth' => ['providers' => ['users' => ['model' => \Illuminate\Foundation\Auth\User::class]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'database' => [
                'default' => 'qualification',
                'migrations' => ['table' => 'migrations'],
                'connections' => ['qualification' => [
                    'driver' => 'pgsql',
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'database' => 'knowkit_chatom_test',
                    'username' => 'knowkit_test',
                    'charset' => 'utf8',
                    'prefix' => '',
                    'search_path' => $this->schema,
                    'sslmode' => 'disable',
                ]],
            ],
            'chatom' => [
                'route' => ['enabled' => false],
                'actors' => ['providers' => [UserProvider::class, BotProvider::class]],
                'tenancy' => ['context' => HostTenant::class],
            ],
        ];
    }

    public function trigger(array $bots = ['tutor']): SendMessageResult
    {
        $learner = ActorRef::of(type: 'user', id: 'learner');
        $channel = $this->app->make(Channels::class)->create(new CreateChannel(
            type: 'messaging',
            createdBy: $learner,
            members: array_map(static fn (string $bot): ActorRef => ActorRef::of(type: 'bot', id: $bot), $bots),
        ));

        return $this->app->make(Messages::class)->send(new SendMessage(
            channel: $channel->id,
            sender: $learner,
            content: MessageDocument::fromPlainText(\KnowKit\Tests\Chatom\Handler\HandlerFixture::corpus()['question']),
            clientMessageId: 'qualification.trigger',
        ));
    }

    public function command(string $name, array $parameters = []): array
    {
        $console = new Console(laravel: $this->app, events: $this->app['events'], version: 'qualification');
        $console->resolveCommands([
            \Chatom\Outbox\Internal\Console\RelayCommand::class,
            \Chatom\Outbox\Internal\Console\OutboxCommand::class,
            \Chatom\Schema\Internal\Console\SchemaDoctorCommand::class,
        ]);
        $output = new BufferedOutput();
        $exit = $console->call(command: $name, parameters: $parameters, outputBuffer: $output);

        return ['exit' => $exit, 'output' => $output->fetch()];
    }

    public function relay(): array
    {
        return $this->command(name: 'chatom:relay', parameters: [
            '--expect-tenant' => Fixture::scope()->tenantId->value,
            '--limit' => 100,
        ]);
    }

    public function rows(string $table): array
    {
        $target = $this->app->make(SchemaSpaceResolver::class)->resolve();

        return $this->db->table($target->table($table))->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /** Advance only the local test schedule, preserving the owner's saved payload and receipt. */
    public function makePendingDeliveryDue(): void
    {
        $target = $this->app->make(SchemaSpaceResolver::class)->resolve();
        $this->db->table($target->table('outbox_deliveries'))
            ->where('consumer_key', 'core.agent')
            ->where('status', 'pending')
            ->update(['available_at' => $this->db->raw("clock_timestamp() - interval '1 second'")]);
    }

    public function close(): void
    {
        if ($this->app->bound('db')) {
            $this->app->make('db')->purge('qualification');
        }
        $this->admin->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        (new Filesystem())->deleteDirectory($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }
}
