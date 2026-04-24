<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Queues.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Tests\Unit\Traits;

use CodeIgniter\Config\Services;
use CodeIgniter\Email\Email;
use CodeIgniter\View\Parser;
use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Job;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Support\TestCase;

/**
 * Extended tests for StateTrait — covers saveRunningFlag, isRunning, clearRunningFlag.
 *
 * @internal
 */
final class StateTraitExtendedTest extends TestCase
{
    private function job(string $name = 'test_running_job'): Job
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named($name);

        return $job;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clean up running flags from previous test runs
        service('cache')->delete('job_running_test_running_job');
    }

    protected function tearDown(): void
    {
        service('cache')->delete('job_running_test_running_job');
        parent::tearDown();
    }

    public function testSaveRunningFlagReturnsBool(): void
    {
        $job    = $this->job();
        $result = $job->saveRunningFlag();
        $this->assertTrue($result);
    }

    public function testIsRunningReturnsTrueAfterSave(): void
    {
        $job = $this->job();
        $job->saveRunningFlag();
        $this->assertTrue($job->isRunning());
    }

    public function testClearRunningFlagRemovesCache(): void
    {
        $job = $this->job();
        $job->saveRunningFlag();
        $this->assertTrue($job->isRunning());
        $job->clearRunningFlag();
        $this->assertFalse($job->isRunning());
    }

    public function testIsRunningReturnsFalseWhenNotSet(): void
    {
        $job = $this->job();
        $this->assertFalse($job->isRunning());
    }

    public function testDisableAndIsEnabled(): void
    {
        $job = $this->job();
        $this->assertTrue($job->isEnabled());
        $job->disable();
        $this->assertFalse($job->isEnabled());
    }

    public function testNotifyOnFailureFluent(): void
    {
        $job    = $this->job();
        $result = $job->notifyOnFailure(true);
        $this->assertSame($job, $result);
        $this->assertTrue($job->shouldNotifyOnFailure());

        $job->notifyOnFailure(false);
        $this->assertFalse($job->shouldNotifyOnFailure());
    }

    public function testNotifyOnSuccessFluent(): void
    {
        $job    = $this->job();
        $result = $job->notifyOnSuccess(true);
        $this->assertSame($job, $result);
        $this->assertTrue($job->shouldNotifyOnSuccess());

        $job->notifyOnSuccess(false);
        $this->assertFalse($job->shouldNotifyOnSuccess());
    }

    public function testNotifyOnCompletionSetsBothFlags(): void
    {
        $job = $this->job();
        $job->notifyOnCompletion(true);
        $this->assertTrue($job->shouldNotifyOnFailure());
        $this->assertTrue($job->shouldNotifyOnSuccess());
    }

    public function testNotifyOnCompletionClearsBothFlags(): void
    {
        $job = $this->job();
        $job->notifyOnCompletion(true);
        $job->notifyOnCompletion(false);
        $this->assertFalse($job->shouldNotifyOnFailure());
        $this->assertFalse($job->shouldNotifyOnSuccess());
    }

    /**
     * @return Email&MockObject
     */
    private function setupEmailMock(bool $sendReturn = true): Email
    {
        $emailMock = $this->getMockBuilder(Email::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setMailType', 'setFrom', 'setTo', 'setSubject', 'setMessage', 'send'])
            ->getMock();

        $emailMock->method('setMailType')->willReturnSelf();
        $emailMock->method('setFrom')->willReturnSelf();
        $emailMock->method('setTo')->willReturnSelf();
        $emailMock->method('setSubject')->willReturnSelf();
        $emailMock->method('setMessage')->willReturnSelf();
        $emailMock->method('send')->willReturn($sendReturn);

        Services::injectMock('email', $emailMock);

        // Mock parser to avoid view file lookup
        $parserMock = $this->getMockBuilder(Parser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData', 'renderString', 'render'])
            ->getMock();

        $parserMock->method('setData')->willReturnSelf();
        $parserMock->method('renderString')->willReturn('Subject text');
        $parserMock->method('render')->willReturn('<p>body</p>');

        Services::injectMock('parser', $parserMock);

        return $emailMock;
    }

    public function testNotifyCallsSendOnEmailService(): void
    {
        $emailMock = $this->setupEmailMock(true);
        $emailMock->expects($this->once())->method('send')->willReturn(true);

        $cfg           = config('Jobs');
        $cfg->from     = 'from@example.com';
        $cfg->fromName = 'Jobs';
        $cfg->to       = 'to@example.com';

        $job    = $this->job();
        $result = new ExecutionResult(true, 'output', null, microtime(true), microtime(true));
        $sent   = $job->notify($result);

        $this->assertTrue($sent);

        Services::resetSingle('email');
        Services::resetSingle('parser');
    }

    public function testNotifyWithFailureCallsSend(): void
    {
        $emailMock = $this->setupEmailMock(false);
        $emailMock->expects($this->once())->method('send')->willReturn(false);

        $job    = $this->job();
        $result = new ExecutionResult(false, null, 'Error occurred', microtime(true), microtime(true));
        $sent   = $job->notify($result);

        $this->assertFalse($sent);

        Services::resetSingle('email');
        Services::resetSingle('parser');
    }

    public function testNotifyWithNullOutputAndError(): void
    {
        $this->setupEmailMock(true);

        $job    = $this->job();
        $result = new ExecutionResult(true, null, null, microtime(true), microtime(true));
        $sent   = $job->notify($result);

        $this->assertTrue($sent);

        Services::resetSingle('email');
        Services::resetSingle('parser');
    }
}
