<?php

declare(strict_types=1);

namespace Tmeister\WppbCli\Commands;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

class NewCommand extends Command
{
    protected function configure()
    {
        $this->setName('new')
            ->setDescription('Create a new WordPress plugin boilerplate')
            ->addArgument('plugin_name', InputArgument::REQUIRED, 'The name of the plugin')
            ->addArgument('plugin_slug', InputArgument::REQUIRED, 'The slug of the plugin')
            ->addArgument('plugin_url', InputArgument::REQUIRED, 'The URL of the plugin')
            ->addArgument('author_name', InputArgument::REQUIRED, 'The name of the author')
            ->addArgument('author_email', InputArgument::REQUIRED, 'The email of the author')
            ->addArgument('author_url', InputArgument::REQUIRED, 'The URL of the author')
            ->addArgument('plugin_description', InputArgument::REQUIRED, 'The description of the plugin');
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $output->write(PHP_EOL.'  <fg=blue>      __        ______  ____  ____     ____ _     ___
        \ \      / /  _ \|  _ \| __ )   / ___| |   |_ _|
         \ \ /\ / /| |_) | |_) |  _ \  | |   | |    | |
          \ V  V / |  __/|  __/| |_) | | |___| |___ | |
           \_/\_/  |_|   |_|   |____/   \____|_____|___|</>'.PHP_EOL.PHP_EOL);

        // Check if current directory looks like WordPress plugins directory
        $currentDir = basename(getcwd());
        if ($currentDir !== 'wp-plugins') {
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
            default: 'Sample Plugin',
            required: true
        ));

        $input->setArgument('plugin_slug', text(
            label: 'What is the slug of your plugin?',
            placeholder: 'sample-plugin',
            default: 'sample-plugin',
            required: true,
            validate: fn (string $value) => preg_match('/^[a-z0-9-]+$/', $value) ? null : 'The plugin slug must be lowercase and use hyphens (e.g., sample-text)'
        ));

        $input->setArgument('plugin_url', text(
            label: 'What is the URL of your plugin?',
            placeholder: 'https://example.com',
            default: 'https://example.com',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_URL) ? null : 'Please enter a valid URL'
        ));

        $input->setArgument('author_name', text(
            label: 'What is the name of the author?',
            placeholder: 'John Doe',
            default: 'John Doe',
            required: true
        ));

        $input->setArgument('author_email', text(
            label: 'What is the email of the author?',
            placeholder: 'john.doe@example.com',
            default: 'john.doe@example.com',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address'
        ));

        $input->setArgument('author_url', text(
            label: 'What is the URL of the author?',
            placeholder: 'https://example.com',
            default: 'https://example.com',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_URL) ? null : 'Please enter a valid URL'
        ));

        $input->setArgument('plugin_description', textarea(
            label: 'What is the description of your plugin?',
            placeholder: 'This is a sample plugin',
            required: true,
            default: 'This is a sample plugin'
        ));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pluginName = $input->getArgument('plugin_name');
        $pluginSlug = $input->getArgument('plugin_slug');
        $pluginUrl = $input->getArgument('plugin_url');
        $authorName = $input->getArgument('author_name');
        $authorEmail = $input->getArgument('author_email');
        $authorUrl = $input->getArgument('author_url');
        $pluginDescription = $input->getArgument('plugin_description');

        $destinationPath = getcwd().DIRECTORY_SEPARATOR.$pluginSlug;

        // Validate destination path
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

        // Delete all in the extract path folder
        spin(function () use ($extractPath) {
            $this->deleteDirectory($extractPath);
        }, 'Cleaning temporary directory...');

        // Get the master zip file from GitHub
        spin(function () use ($remoteGithubMaster, $downloadPath, $extractPath) {
            $this->downloadAndUnzip($remoteGithubMaster, $downloadPath, $extractPath);
        }, 'Downloading WordPress Plugin Boilerplate...');

        // Replace all the strings in the files
        spin(
            function () use ($extractPath, $repoName, $pluginSlug, $pluginName, $pluginUrl, $authorName, $authorEmail, $authorUrl, $pluginDescription) {
                $this->replaceStrings($extractPath, $repoName, $pluginSlug, $pluginName, $pluginUrl, $authorName, $authorEmail, $authorUrl, $pluginDescription);
            },
            'Customizing plugin files...'
        );

        // Copy files to the new folder
        $destinationPath = spin(function () use ($extractPath, $repoName, $pluginSlug) {
            return $this->copyToNewFolder($extractPath, $repoName, $pluginSlug);
        }, 'Creating plugin directory...');

        if ($destinationPath === false) {
            return Command::FAILURE;
        }

        // Track Build
        $this->trackDownload();

        info('Plugin created successfully in: '.$pluginSlug);
        info('Next steps:');
        note(' - Navigate to the plugin directory: cd '.$pluginSlug);
        note(' - Activate the plugin: wp plugin activate '.$pluginSlug.' or directly in the WordPress admin');

        return Command::SUCCESS;
    }

    private function deleteDirectory($dir)
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

    private function downloadAndUnzip($url, $zipFile, $extractPath)
    {
        if (file_put_contents($zipFile, file_get_contents($url)) === false) {
            throw new \RuntimeException('Failed to download the zip file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFile) === true) {
            // Validate zip contents before extraction
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (! $this->isValidZipEntry($filename, $extractPath)) {
                    $zip->close();
                    unlink($zipFile);
                    throw new \RuntimeException("Invalid zip entry: $filename");
                }
            }

            $zip->extractTo($extractPath);
            $zip->close();
        } else {
            throw new \RuntimeException('Failed to open the zip file.');
        }

        if (! unlink($zipFile)) {
            throw new \RuntimeException('Failed to delete the temporary zip file.');
        }
    }

    private function replaceStrings($extractPath, $repoName, $pluginSlug, $pluginName, $pluginUrl, $authorName, $authorEmail, $authorUrl, $pluginDescription)
    {
        $destination = $extractPath.'/'.$repoName;

        // Find the 'plugin-name' directory
        $pluginNameDir = $this->findPluginNameDirectory($destination);
        if (! $pluginNameDir) {
            throw new \RuntimeException("Could not find 'plugin-name' directory in {$destination}");
        }

        // Rename the plugin-name folder to the plugin slug
        $newPluginDir = dirname($pluginNameDir).'/'.$pluginSlug;
        rename($pluginNameDir, $newPluginDir);

        // Get all the files and rename the plugin-name to the plugin slug
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
                ]);
            }
        }
    }

    private function findPluginNameDirectory($baseDir)
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $dir) {
            if ($dir->isDir() && $dir->getFilename() === 'plugin-name') {
                return $dir->getPathname();
            }
        }

        return false;
    }

    private function replaceInFile($file, $replacements)
    {
        $content = file_get_contents($file);
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
        file_put_contents($file, $content);
    }

    private function copyToNewFolder($extractPath, $repoName, $pluginSlug)
    {
        $sourcePath = $extractPath.DIRECTORY_SEPARATOR.$repoName.DIRECTORY_SEPARATOR.$pluginSlug;
        $destinationPath = getcwd().DIRECTORY_SEPARATOR.$pluginSlug;

        if (! $this->isValidPath($destinationPath)) {
            throw new \RuntimeException('Invalid destination path.');
        }

        if (! mkdir($destinationPath, 0755, true)) {
            throw new \RuntimeException('Failed to create destination directory.');
        }

        $this->recursiveCopy($sourcePath, $destinationPath);

        // Clean up temporary files
        $this->deleteDirectory($extractPath);

        return $destinationPath;
    }

    private function recursiveCopy($src, $dst)
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

    private function trackDownload()
    {
        // Implement your tracking logic here
        // For example, you could use a service like Google Analytics
    }

    // New helper methods for security checks

    private function isValidPath($path)
    {
        $realPath = realpath(dirname($path));

        return $realPath !== false && strpos($path, $realPath) === 0;
    }

    private function isValidZipEntry($filename, $extractPath)
    {
        $targetPath = $extractPath.DIRECTORY_SEPARATOR.$filename;

        return strpos($targetPath, $extractPath.DIRECTORY_SEPARATOR) === 0;
    }
}
