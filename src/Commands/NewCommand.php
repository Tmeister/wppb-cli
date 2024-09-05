<?php

declare(strict_types=1);

namespace Tmeister\WppbCli\Commands;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;
use function Laravel\Prompts\warning;

/**
 * Class NewCommand
 *
 * This command creates a new WordPress plugin boilerplate.
 */
class NewCommand extends Command
{
    private const CONFIG_FILE_NAME = '.wppb';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName('new')
            ->setDescription('Create a new WordPress plugin using the WPPB boilerplate')
            ->addArgument('plugin_name', InputArgument::REQUIRED, 'The name of the plugin')
            ->addArgument('plugin_slug', InputArgument::REQUIRED, 'The slug of the plugin')
            ->addArgument('plugin_url', InputArgument::REQUIRED, 'The URL of the plugin')
            ->addArgument('author_name', InputArgument::REQUIRED, 'The name of the author')
            ->addArgument('author_email', InputArgument::REQUIRED, 'The email of the author')
            ->addArgument('author_url', InputArgument::REQUIRED, 'The URL of the author')
            ->addArgument('plugin_description', InputArgument::REQUIRED, 'The description of the plugin');
    }

    /**
     * Interact with the user to get input.
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        $output->write(PHP_EOL.'  <fg=blue>      __        ______  ____  ____     ____ _     ___
        \ \      / /  _ \|  _ \| __ )   / ___| |   |_ _|
         \ \ /\ / /| |_) | |_) |  _ \  | |   | |    | |
          \ V  V / |  __/|  __/| |_) | | |___| |___ | |
           \_/\_/  |_|   |_|   |____/   \____|_____|___|</>'.PHP_EOL.PHP_EOL);

        $currentDir = basename(getcwd());
        if (! str_ends_with(getcwd(), 'wp-content/plugins')) {
            $continue = confirm(
                label: 'The current path does not look like the WordPress plugin directory. Do you want to continue?',
                default: false
            );

            if (! $continue) {
                error('Plugin creation cancelled.');
                exit(1);
            }
        }

        $input->setArgument('plugin_name', text(
            label: 'What is the name of your plugin?',
            placeholder: 'Sample Plugin',
            required: true
        ));

        $input->setArgument('plugin_slug', text(
            label: 'What is the slug of your plugin?',
            placeholder: 'sample-plugin',
            required: true,
            validate: fn (string $value) => preg_match('/^[a-z0-9-]+$/', $value) ? null : 'The plugin slug must be lowercase and use hyphens (e.g., sample-text)'
        ));

        $input->setArgument('plugin_url', text(
            label: 'What is the URL of your plugin?',
            placeholder: 'https://example.com',
            default: 'https://',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_URL) ? null : 'Please enter a valid URL'
        ));

        $config = $this->readConfigFile();

        $input->setArgument('author_name', text(
            label: 'What is the name of the author?',
            placeholder: 'John Doe',
            default: $config['author'] ?? '',
            required: true
        ));

        $input->setArgument('author_email', text(
            label: 'What is the email of the author?',
            placeholder: 'john.doe@example.com',
            default: $config['authorEmail'] ?? '',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address'
        ));

        $input->setArgument('author_url', text(
            label: 'What is the URL of the author?',
            placeholder: 'https://example.com',
            default: $config['authorUrl'] ?? 'https://',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_URL) ? null : 'Please enter a valid URL'
        ));

        $input->setArgument('plugin_description', textarea(
            label: 'What is the description of your plugin?',
            placeholder: 'This is a sample plugin',
            required: true,
        ));
    }

    /**
     * Execute the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pluginName = $input->getArgument('plugin_name');
        $pluginSlug = $input->getArgument('plugin_slug');
        $pluginUrl = $input->getArgument('plugin_url');
        $authorName = $input->getArgument('author_name');
        $authorEmail = $input->getArgument('author_email');
        $authorUrl = $input->getArgument('author_url');
        $pluginDescription = $input->getArgument('plugin_description');

        $destinationPath = getcwd().DIRECTORY_SEPARATOR.$pluginSlug;

        if (! $this->isValidPath($destinationPath)) {
            error('Error: Invalid destination path.');

            return Command::FAILURE;
        }

        if (is_dir($destinationPath)) {
            error("Error: A folder named '$pluginSlug' already exists in the current directory. Please choose a different name.");

            return Command::FAILURE;
        }

        $output->writeln('<info>Starting plugin creation process...</info>');

        $tmpFolder = sys_get_temp_dir();
        $downloadPath = $tmpFolder.'/master.zip';
        $extractPath = $tmpFolder.'/source';
        $remoteGithubMaster = 'https://github.com/DevinVinson/WordPress-Plugin-Boilerplate/archive/refs/heads/master.zip';
        $repoName = 'WordPress-Plugin-Boilerplate-master';

        spin(function () use ($extractPath) {
            $this->deleteDirectory($extractPath);
        }, 'Cleaning temporary directory...');

        spin(function () use ($remoteGithubMaster, $downloadPath, $extractPath) {
            $this->downloadAndUnzip($remoteGithubMaster, $downloadPath, $extractPath);
        }, 'Downloading WordPress Plugin Boilerplate...');

        spin(
            function () use ($extractPath, $repoName, $pluginSlug, $pluginName, $pluginUrl, $authorName, $authorEmail, $authorUrl, $pluginDescription) {
                $this->replaceStrings($extractPath, $repoName, $pluginSlug, $pluginName, $pluginUrl, $authorName, $authorEmail, $authorUrl, $pluginDescription);
            },
            'Customizing plugin files...'
        );

        $destinationPath = spin(function () use ($extractPath, $repoName, $pluginSlug) {
            return $this->copyToNewFolder($extractPath, $repoName, $pluginSlug);
        }, 'Creating plugin directory...');

        if ($destinationPath === false) {
            return Command::FAILURE;
        }

        $this->trackDownload();

        info('Plugin created successfully in: '.$pluginSlug);
        note(' - Activate the plugin: wp plugin activate '.$pluginSlug.' or directly in the WordPress admin');
        note(' - Start coding!');
        info('Don\'t forget to follow me on X aka Twitter: @tmeister');

        return Command::SUCCESS;
    }

    /**
     * Delete a directory and its contents.
     */
    private function deleteDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (is_dir($dir.'/'.$object)) {
                        $this->deleteDirectory($dir.'/'.$object);
                    } else {
                        unlink($dir.'/'.$object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Download and unzip a file.
     *
     * @throws RuntimeException
     */
    private function downloadAndUnzip(string $url, string $zipFile, string $extractPath): void
    {
        if (file_put_contents($zipFile, file_get_contents($url)) === false) {
            throw new RuntimeException('Failed to download the zip file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFile) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (! $this->isValidZipEntry($filename, $extractPath)) {
                    $zip->close();
                    unlink($zipFile);
                    throw new RuntimeException("Invalid zip entry: $filename");
                }
            }

            $zip->extractTo($extractPath);
            $zip->close();
        } else {
            throw new RuntimeException('Failed to open the zip file.');
        }

        if (! unlink($zipFile)) {
            throw new RuntimeException('Failed to delete the temporary zip file.');
        }
    }

    /**
     * Replace strings in files.
     */
    private function replaceStrings(string $extractPath, string $repoName, string $pluginSlug, string $pluginName, string $pluginUrl, string $authorName, string $authorEmail, string $authorUrl, string $pluginDescription): void
    {
        $destination = $extractPath.'/'.$repoName;

        $pluginNameDir = $this->findPluginNameDirectory($destination);
        if (! $pluginNameDir) {
            throw new RuntimeException("Could not find 'plugin-name' directory in {$destination}");
        }

        $newPluginDir = dirname($pluginNameDir).'/'.$pluginSlug;
        rename($pluginNameDir, $newPluginDir);

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($newPluginDir));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $newName = str_replace('plugin-name', $pluginSlug, $file->getPathname());
                rename($file->getPathname(), $newName);
                $this->replaceInFile($newName, [
                    'http://example.com/plugin-name-uri/' => $pluginUrl,
                    'WordPress Plugin Boilerplate' => $pluginName,
                    'This is a short description of what the plugin does. It\'s displayed in the WordPress admin area.' => $pluginDescription,
                    'Your Name or Your Company' => $authorName,
                    'plugin_name' => lcfirst(str_replace('-', '', ucwords($pluginSlug, '-'))),
                    'http://example.com' => $authorUrl,
                    'plugin-name' => $pluginSlug,
                    'Your Name <email@example.com>' => "$authorName <$authorEmail>",
                    'Plugin_Name' => str_replace('-', '_', ucwords($pluginSlug, '-')),
                    'PLUGIN_NAME_VERSION' => strtoupper(str_replace('-', '_', $pluginSlug)).'_VERSION',
                ]);
            }
        }
    }

    /**
     * Find the 'plugin-name' directory.
     */
    private function findPluginNameDirectory(string $baseDir): string|false
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $dir) {
            if ($dir->isDir() && $dir->getFilename() === 'plugin-name') {
                return $dir->getPathname();
            }
        }

        return false;
    }

    /**
     * Replace strings in a file.
     */
    private function replaceInFile(string $file, array $replacements): void
    {
        $content = file_get_contents($file);
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
        file_put_contents($file, $content);
    }

    /**
     * Copy files to a new folder.
     *
     * @throws RuntimeException
     */
    private function copyToNewFolder(string $extractPath, string $repoName, string $pluginSlug): string|false
    {
        $sourcePath = $extractPath.DIRECTORY_SEPARATOR.$repoName.DIRECTORY_SEPARATOR.$pluginSlug;
        $destinationPath = getcwd().DIRECTORY_SEPARATOR.$pluginSlug;

        if (! $this->isValidPath($destinationPath)) {
            throw new RuntimeException('Invalid destination path.');
        }

        if (! mkdir($destinationPath, 0750, true)) {
            throw new RuntimeException('Failed to create destination directory.');
        }

        $this->recursiveCopy($sourcePath, $destinationPath);

        $this->deleteDirectory($extractPath);

        return $destinationPath;
    }

    /**
     * Recursively copy a directory.
     */
    private function recursiveCopy(string $src, string $dst): void
    {
        $dir = opendir($src);
        @mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src.'/'.$file)) {
                    $this->recursiveCopy($src.'/'.$file, $dst.'/'.$file);
                } else {
                    copy($src.'/'.$file, $dst.'/'.$file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * Track the download.
     */
    private function trackDownload(): void
    {
        // Implement your tracking logic here
        // For example, you could use a service like Google Analytics
    }

    /**
     * Check if a path is valid.
     */
    private function isValidPath(string $path): bool
    {
        $realPath = realpath(dirname($path));

        return $realPath !== false && str_starts_with($path, $realPath);
    }

    /**
     * Check if a zip entry is valid.
     */
    private function isValidZipEntry(string $filename, string $extractPath): bool
    {
        $targetPath = $extractPath.DIRECTORY_SEPARATOR.$filename;

        return str_starts_with($targetPath, $extractPath.DIRECTORY_SEPARATOR);
    }

    /**
     * Read the configuration file from the user's home directory.
     *
     * @return array<string, string>
     */
    private function readConfigFile(): array
    {
        $homeDir = $this->getHomeDirectory();
        $configPath = $homeDir.DIRECTORY_SEPARATOR.self::CONFIG_FILE_NAME;

        if (! file_exists($configPath)) {
            return [];
        }

        $configContent = file_get_contents($configPath);
        $config = [];

        foreach (explode("\n", $configContent) as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $config[trim($key)] = trim($value);
        }

        if (! $this->isValidConfig($config)) {
            warning('We found the .wppb file but the format is incorrect. Continuing without prefilling the fields.');

            return [];
        }

        return $config;
    }

    /**
     * Get the user's home directory.
     */
    private function getHomeDirectory(): string
    {
        return $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '';
    }

    /**
     * Validate the configuration array.
     */
    private function isValidConfig(array $config): bool
    {
        return isset($config['author'], $config['authorEmail'], $config['authorUrl']) &&
               is_string($config['author']) &&
               is_string($config['authorEmail']) &&
               is_string($config['authorUrl']);
    }
}
