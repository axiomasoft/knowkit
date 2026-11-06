<?php
declare(strict_types=1);

// Adversarial QA protocol fixture; these fabricated reports never qualify product groups.
[$script, $scenario, $destination] = $argv;
$case = '<testcase classname="Fixture" name="testCase"/>';
$xml = match ($scenario) {
    'valid', 'child-failure' => '<testsuites><testsuite tests="1">' . $case . '</testsuite></testsuites>',
    'zero' => '<testsuites><testsuite tests="0"/></testsuites>',
    'missing' => '<testsuites><testcase classname="Other" name="testOther"/></testsuites>',
    'duplicate' => '<testsuites>' . $case . $case . '</testsuites>',
    'skipped' => '<testsuites><testcase classname="Fixture" name="testCase"><skipped/></testcase></testsuites>',
    'failed' => '<testsuites><testcase classname="Fixture" name="testCase"><failure/></testcase></testsuites>',
    'malformed' => '<broken>',
    'lying-count' => '<testsuites><testsuite tests="10">' . $case . '</testsuite></testsuites>',
    'lying-skips' => '<testsuites><testsuite tests="1" skipped="1">' . $case . '</testsuite></testsuites>',
    default => throw new RuntimeException('Unknown fixture scenario'),
};
file_put_contents($destination, $xml);
exit($scenario === 'child-failure' ? 17 : 0);
