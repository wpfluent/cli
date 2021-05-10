<?php

namespace WPFluent\Cli;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessFailedException;

class CreateProjectCommand extends Command
{
    protected $path = null;

    protected $config = [];

    protected $questions = [
        'plugin_name' => 'Plugin Name',
        'plugin_version' => 'Plugin Version',
        'plugin_description' => 'Plugin Description',
        'plugin_uri' => 'Plugin URI',
        'plugin_license' => 'Plugin License',
        'plugin_text_domain' => 'Plugin Text Domain',
        'plugin_author_name' => 'Author Name',
        'plugin_author_uri' => 'Author URI',
        'plugin_namespace' => 'Plugin Namespace',
    ];

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('create-project')
            ->addArgument(
                'directory',
                InputArgument::OPTIONAL,
                'Directory name to install the plugin.'
            )
            ->setDescription(
                'Installs WPFluent - The plugin development framework for WordPress.'
            );
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('The Zip PHP extension is not installed.');
            }

            $this->input = $input;
            $this->output = $output;
            $this->helper = $this->getHelper('question');

            $this->collectPluginInformationFromUser();
            $this->confirm() && $this->installApplication();

            return 0;
        } catch (RuntimeException $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    protected function collectPluginInformationFromUser()
    {
        $this->config['plugin_name'] = $this->getPluginName();
        $this->config['plugin_version'] = $this->getPluginVersion();
        $this->config['plugin_text_domain'] = $this->getPluginTextDomain();
        $this->config['plugin_description'] = $this->getPluginDescription();
        $this->config['plugin_uri'] = $this->getPluginUri();
        $this->config['plugin_license'] = $this->getPluginLicense();
        $this->config['plugin_author_name'] = $this->getPluginAuthorName();
        $this->config['plugin_author_uri'] = $this->getPluginAuthorUri();
        $this->config['plugin_namespace'] = $this->getPluginNamespace();
    }

    protected function getPluginName()
    {
        $this->config['directory'] = $this->getPluginDirectory();

        $parts = array_map(function ($item) {
            return ucfirst($item);
        }, preg_split('/\s|-|_/', $this->config['directory']));

        $directory = implode(' ', $parts);
        $question = new Question('Plugin Name ('.$directory.'): ', $directory);
        return $this->helper->ask($this->input, $this->output, $question);
    }

    protected function getPluginDirectory()
    {
        $directory = $this->input->getArgument('directory');
        $directory = $directory ? getcwd().DIRECTORY_SEPARATOR.$directory : getcwd();
        $this->path = $directory;
        
        return trim(substr(
            $directory, strrpos($directory, DIRECTORY_SEPARATOR)
        ), DIRECTORY_SEPARATOR);
    }

    protected function getPluginTextDomain()
    {
        return $this->config['directory'];
    }

    protected function getPluginDescription()
    {
        $desc = $this->config['plugin_name'].' WordPress Plugin';

        return $this->helper->ask($this->input, $this->output, new Question(
            'Plugin Short Description ('.$desc.'): ', $desc
        ));
    }

    protected function getPluginVersion()
    {
        return $this->helper->ask($this->input, $this->output, new Question(
            'Plugin Version (1.0.0): ', '1.0.0'
        ));
    }

    protected function getPluginUri()
    {
        return $this->helper->ask($this->input, $this->output, new Question(
            'Plugin URI: ', false
        ));
    }

    protected function getPluginAuthorName()
    {
        $question = new Question('Author Name: ', false);
        return $this->helper->ask($this->input, $this->output, $question);
    }

    protected function getPluginAuthorUri()
    {
        return $this->helper->ask($this->input, $this->output, new Question(
            'Author URI: ', false
        ));
    }

    protected function getPluginLicense()
    {
        return $this->helper->ask($this->input, $this->output, new Question(
            'Plugin License (GPLv2 or later): ', 'GPLv2 or later'
        ));
    }

    protected function getPluginNamespace()
    {
        $directory = $this->config['directory'];
        
        $parts = array_map(function ($item) {
            return ucfirst($item);
        }, preg_split('/\s|-|_/', $directory));

        $nameSpace = implode('', $parts);

        $question = new Question('Plugin Namespace ('.$nameSpace.'): ', $nameSpace);

        return $this->helper->ask($this->input, $this->output, $question);
    }

    protected function confirm()
    {
        $this->output->writeln('<info></info>');

        foreach ($this->questions as $key => $value) {
            $this->output->writeln('<info>' . $value . ' : ' . $this->config[$key] . '</info>');
        }

        return $this->helper->ask($this->input, $this->output, new ConfirmationQuestion(
            'Continue with this configuration? (y/n): ', true
        ));
    }

    protected function installApplication()
    {
        $this->output->writeln('<info>Please wait, creating the plugin skeleton...</info>');

        $this->download($tempFile = $this->makeTempFileName(), $this->getVersion())
            ->extractFIles($tempFile)
            ->installAndSetNamespace()
            ->cleanUp($tempFile);

        $this->output->writeln('<comment>You are ready to build your amazing plugin!</comment>');
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeTempFileName()
    {
        return getcwd().'/wpfluent_source'.md5(time().uniqid()).'.zip';
    }

    /**
     * Get the version that should be downloaded.
     *
     * @return string
     */
    protected function getVersion()
    {
        return 'master';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $tempFile
     * @param  string  $version
     * @return $this
     */
    protected function download($tempFile, $version = 'master')
    {
        $uri = "https://github.com/wpfluent/wpfluent/archive/{$version}.zip";

        file_put_contents($tempFile, (new Client)->get($uri)->getBody());

        return $this;
    }

    /**
     * Extract the Zip file into the given path.
     *
     * @param  string  $tempFile
     * @return $this
     */
    protected function extractFIles($tempFile)
    {
        $archive = new ZipArchive;
        $archive->open($tempFile);
        $archive->extractTo($this->path);
        $archive->close();

        return $this;
    }

    /**
     * Move the directories from sub-directory to root
     * 
     * @return void
     */
    protected function installAndSetNamespace()
    {
        $this->installWPFluent();

        $this->installFramework();

        return $this;
    }

    protected function installWPFluent()
    {
        $fileSystem = new FileSystem();

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->path.'/wpfluent-master', RecursiveDirectoryIterator::SKIP_DOTS
        ), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $name = $iterator->getSubPathName();

            if ($item->isDir()) {
                $fileSystem->mkdir($this->path . DIRECTORY_SEPARATOR . $name);
            } else {
                $newFileName = $this->path . DIRECTORY_SEPARATOR . $name;
                $fileSystem->copy($item, $newFileName);
                $content = file_get_contents($newFileName);

                if ($name == 'composer.json') {
                    continue;
                }

                $content = str_replace([
                    'WPFluentApp\\Database',
                    'WPFluentApp',
                    'WPFluentFramework\\',
                    'wpfluent_loaded'
                    ], [
                        $this->config['plugin_namespace'] . '\\Database',
                        $this->config['plugin_namespace'] . '\\App',
                        $this->config['plugin_namespace'] . '\\Framework\\',
                        strtolower($this->config['plugin_namespace']) . '_loaded'
                    ], $content
                );

                file_put_contents($newFileName, $content);
            }
        }
        
        $fileSystem->remove($this->path.'/wpfluent-master');

        $this->updatePluginPHPFile()
            ->updateRootComposerFile()
            ->updateGlobalDevFile()
            ->setAppConfig()
            ->updateAdminMenuHandler();
    }

    protected function updatePluginPHPFile()
    {
        $fileSystem = new FileSystem();
        
        if (!$fileSystem->exists($pluginFile = $this->path.'/plugin.php')) {
            return;
        }

        $content = file_get_contents($pluginFile);
            
        $docBlocks = [
            'Plugin Name: WPFluent',
            'Description: A WordPress Plugin.',
            'Version: 1.0.0',
            'Author: Sheikh Heera',
            'Author URI: https://heera.it',
            'Plugin URI: https://heera.it',
            'License: GPLv2 or later',
            'Text Domain: WPFluent',
            'Domain Path: /language'
        ];

        $content = str_replace($docBlocks, [
            'Plugin Name: ' . $this->config['plugin_name'],
            'Description: ' . $this->config['plugin_description'],
            'Version: ' . $this->config['plugin_version'],
            'Author: ' . $this->config['plugin_author_name'],
            'Author URI: ' . $this->config['plugin_author_uri'],
            'Plugin URI: ' . $this->config['plugin_uri'],
            'License: ' . $this->config['plugin_license'],
            'Text Domain: ' . $this->config['plugin_text_domain'],
            'Domain Path: /language'
        ], $content);

        file_put_contents($pluginFile, $content);

        return $this;
    }

    protected function updateRootComposerFile()
    {
        $rootNamespace = $this->config['plugin_namespace'];

        $composer = file_get_contents($this->path . '/composer.json');
        $composer = json_decode($composer, true);
        $composer['autoload']['psr-4'] = [
            $rootNamespace . "\\App\\" => "app/",
            $rootNamespace . "\\Framework\\" => "vendor/wpfluent/framework/src/WPFluent"
        ];

        $composer['scripts']['post-update-cmd'] = [
            $rootNamespace . "\\App\\ComposerScript::postUpdate"
        ];

        $composer['extra']['wpfluent']['namespace']['current'] = $rootNamespace;
        $composer = json_encode($composer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        file_put_contents($this->path . '/composer.json', $composer . "\n");

        return $this;
    }

    protected function updateGlobalDevFile()
    {
        $content = file_get_contents($this->path . '/boot/globals_dev.php');
        
        $prefix = strtolower($this->config['plugin_namespace']);
        
        $content = str_replace(
            ['wpfluent_eqL', 'wpfluent_gql'],
            [$prefix . '_eql', $prefix . '_gql'],
            $content
        );

        file_put_contents($this->path . '/boot/globals_dev.php', $content . "\n");

        return $this;
    }

    protected function setAppConfig()
    {
        $fileSystem = new FileSystem();

        if (!$fileSystem->exists($configFile = $this->path.'/config/app.php')) {
            return;
        }

        $appConfig = require $configFile;

        $slug = strtolower($this->config['plugin_namespace']);

        $appConfig['name'] = $this->config['plugin_name'];
        $appConfig['slug'] = $slug;
        $appConfig['text_domain'] = $slug;
        $appConfig['hook_prefix'] = $slug;
        $appConfig['rest_namespace'] = $slug;

        $content = var_export($appConfig, true);
        $msg = "// Auto generated by wpfluent Installer.";
        file_put_contents($configFile, "<?php\n\n $msg\n\n return $content;\n");

        return $this;
    }

    protected function updateAdminMenuHandler()
    {
        $adminMenuHandler = $this->path.'/app/Hooks/Handlers/AdminMenuHandler.php';
        
        $fileSystem = new FileSystem();

        if (!$fileSystem->exists($adminMenuHandler)) {
            return;
        }

        $appConfig = require $this->path.'/config/app.php';

        $content = file_get_contents($adminMenuHandler);

        $content = str_replace(
            [
                'wpfluent_plugin_name',
                'wpfluent_plugin_slug',
                'wpfluent_plugin_textDomain'
            ],
            [
                $appConfig['name'],
                $appConfig['slug'],
                $appConfig['text_domain']
            ],
            $content
        );

        file_put_contents($adminMenuHandler, $content . "\n");
    }

    protected function installFramework()
    {
        chdir($this->path);

        $executableFinder = new ExecutableFinder();
        $binary = $executableFinder->find('composer');

        $process = new Process([$binary, 'install']);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $this->output->writeln('<info>' . $process->getOutput() . '</info>');

        $itr = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->path.'/vendor/wpfluent/framework/src/', RecursiveDirectoryIterator::SKIP_DOTS
        ), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($itr as $file) {
            if ($file->isDir()) { 
                continue;
            }

            $fileName = $file->getPathname();
            $content = file_get_contents($fileName);
            $content = str_replace(
                'WPFluent\\',
                $this->config['plugin_namespace'] . '\\Framework\\',
                $content
            );

            file_put_contents($fileName, $content);
        }
    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string  $tempFile
     * @return $this
     */
    protected function cleanUp($tempFile)
    {
        @chmod($tempFile, 0777);
        @unlink($tempFile);
    }
}
