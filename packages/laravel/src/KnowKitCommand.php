<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use Illuminate\Console\Command;
use KnowKit\Adapter\Cli\Response;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class KnowKitCommand extends Command
{
    protected $signature = 'knowkit {words*} {--json} {--input=} {--file=}
        {--idempotency-key=} {--expected-epoch=} {--expected-revision=}';

    protected $description = 'Invoke explicitly configured KnowKit application use cases';

    public function run(InputInterface $input, OutputInterface $output): int
    {
        ob_start();
        try {
            return parent::run(input: $input, output: $output);
        } catch (ExceptionInterface) {
            return $this->failure(response: Response::error(code: 'invalid_command', exit: 2), output: $output);
        } catch (\Throwable) {
            return $this->failure(response: Response::error(code: 'internal_error', exit: 70), output: $output);
        } finally {
            ob_end_clean();
        }
    }

    public function handle(ConsoleKernel $kernel): int
    {
        $stdin = $this->option('input') === '-' ? (string) stream_get_contents(STDIN, 65537) : '';
        $response = $kernel->run(arguments: $this->coreArguments(), stdin: $stdin);
        $this->output->write(
            messages: $response->json() . "\n",
            options: OutputInterface::OUTPUT_RAW,
        );

        return $response->exit;
    }

    /** @return list<string> */
    private function coreArguments(): array
    {
        if ($this->input instanceof ArgvInput) {
            // Preserve duplicate flags and ordering for the core grammar to validate.
            return $this->input->getRawTokens(strip: $this->input->hasArgument('command'));
        }

        $arguments = $this->argument('words');
        foreach (['input', 'file', 'idempotency-key', 'expected-epoch', 'expected-revision'] as $name) {
            if ($this->option($name) !== null) {
                $arguments[] = '--' . $name . '=' . $this->option($name);
            }
        }
        $arguments[] = '--json';

        return $arguments;
    }

    private function failure(Response $response, OutputInterface $output): int
    {
        $output->write(messages: $response->json() . "\n", options: OutputInterface::OUTPUT_RAW);

        return $response->exit;
    }
}
