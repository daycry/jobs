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

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\CLI;
use Config\Autoload;
use Exception;

/**
 * Publishes package configuration files into the application namespace so they can be customized.
 */
class PublishCommand extends BaseJobsCommand
{
    /**
     * The Command's name
     *
     * @var string
     */
    protected $name = 'jobs:publish';

    /**
     * the Command's short description
     *
     * @var string
     */
    protected $description = 'Publish the jobs config file.';

    /**
     * the Command's usage
     *
     * @var string
     */
    protected $usage = 'jobs:publish';

    /**
     * Source Path
     *
     * @var string
     */
    protected $sourcePath = '';

    /**
     * Assets Path
     *
     * @var string
     */
    protected $assetsPath = '';

    /**
     * Enables task running
     */
    public function run(array $params): void
    {
        $this->determineSourcePath();

        // Config
        if (CLI::prompt('Publish Config file?', ['y', 'n']) === 'y') {
            $this->publishConfig();
        }

        // $this->call('cronjob:assets');
    }

    protected function publishConfig(): void
    {
        $path = "{$this->sourcePath}/Config/Jobs.php";

        $content = file_get_contents($path);
        $content = str_replace('namespace Daycry\Jobs\Config', 'namespace Config', $content);
        $content = str_replace('extends BaseConfig', 'extends \\Daycry\\Jobs\\Config\\Jobs', $content);

        $this->writeFile('Config/Jobs.php', $content);
    }

    /**
     * Determines the current source path from which all other files are located.
     */
    protected function determineSourcePath(): void
    {
        $this->sourcePath = realpath(__DIR__ . '/../');

        if ($this->sourcePath === '/' || empty($this->sourcePath)) {
            CLI::error('Unable to determine the correct source directory. Bailing.');

            exit();
        }
    }

    /**
     * Write a file, catching any exceptions and showing a
     * nicely formatted error.
     */
    protected function writeFile(string $path, string $content): void
    {
        $config  = new Autoload();
        $appPath = $config->psr4[APP_NAMESPACE];

        $directory = dirname($appPath . $path);

        if (! is_dir($directory)) {
            mkdir($directory);
        }

        try {
            write_file($appPath . $path, $content);
        } catch (Exception $e) {
            $this->showError($e);

            exit();
        }

        $path = str_replace($appPath, '', $path);

        CLI::write(CLI::color('  created: ', 'green') . $path);
    }
}
