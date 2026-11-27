<?php

declare(strict_types=1);

namespace KnowKit\Laravel;

use Illuminate\Console\Command;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Result;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class KnowKitCommand extends Command
{
    protected $signature = 'knowkit {words*} {--instance=} {--json} {--input=} {--file=}
        {--idempotency-key=} {--expected-epoch=} {--expected-revision=}';

    protected $description = 'Invoke explicitly configured KnowKit application use cases';

    public function run(InputInterface $input, OutputInterface $output): int
    {
        ob_start();
        try {
            try {
                return parent::run(input: $input, output: $output);
            } finally {
                ob_end_clean();
            }
        } catch (ExceptionInterface) {
            return $this->failure(response: Response::error(code: 'invalid_command', exit: 2), output: $output);
        } catch (\Throwable $error) {
            if ($error instanceof DomainFailure) {
                return $this->failure(response: Response::result(Result::failure($error)), output: $output);
            }

            return $this->failure(response: Response::error(code: 'internal_error', exit: 70), output: $output);
        }
    }

    public function handle(KnowKitManager $manager): int
    {
        $instance = $this->option('instance');
        $kernel = $manager->console(is_string($instance) ? $instance : null);
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
            // Preserve duplicate flags and ordering for the core grammar to validate,
            // stripping only the Laravel-level --instance selector.
            $tokens = $this->input->getRawTokens(strip: $this->input->hasArgument('command'));
            $filtered = [];
            $skipNext = false;
            foreach ($tokens as $token) {
                if ($skipNext) {
                    $skipNext = false;
                    continue;
                }
                if ($token === '--instance') {
                    $skipNext = true;
                    continue;
                }
                if (str_starts_with($token, '--instance=')) {
                    continue;
                }
                $filtered[] = $token;
            }

            return $filtered;
        }

        $words = $this->argument('words');
        $arguments = [];
        if (is_array($words)) {
            foreach ($words as $word) {
                if (is_scalar($word)) {
                    $arguments[] = (string) $word;
                }
            }
        }
        foreach (['input', 'file', 'idempotency-key', 'expected-epoch', 'expected-revision'] as $name) {
            $option = $this->option($name);
            if (is_scalar($option)) {
                $arguments[] = '--' . $name . '=' . (string) $option;
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
