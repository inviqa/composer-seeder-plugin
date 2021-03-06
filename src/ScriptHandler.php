<?php
namespace Inviqa\Seeder;

use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
use Twig_Loader_Filesystem;
use Twig_Environment;


class ScriptHandler
{
    /**
     * @param IOInterface
     */
    private $io;
    
    /**
     * @var ProcessExecutor
     */
    private $process;

    /**
     * @var array
     */
    private $gitConfig = null;

    public function __construct(IOInterface $io, ProcessExecutor $process = null)
    {
        $this->io = $io;
        $this->process = $process ?: new ProcessExecutor($this->io);
    }

    private function ask($question, $default = null, $validate = null)
    {
        if ($default) {
            $question = sprintf('%s [<comment>%s</comment>]: ', $question, $default);
        } else {
            $question = sprintf('%s: ', $question);
        }
        if ($validate) {
            return $this->io->askAndValidate($question, $validate, null, $default);
        } else {
            return $this->io->ask($question, $default);
        }
    }

    private function filterDirectories($directory)
    {
        $finder = new Finder();
        $finder
            ->ignoreDotFiles(false)
            ->files()
            ->name('/\.seed\.twig$/')
            ->in($directory);
        return $finder;
    }

    private function formatDefaultNamespace($package)
    {
        return str_replace(['/','-','_'], ['\\',''], ucwords($package, '/-_'));
    }

    private function getGitConfig()
    {
        if (null !== $this->gitConfig) {
            return $this->gitConfig;
        }
        $finder = new ExecutableFinder();
        $gitBin = $finder->find('git');
        $cmd = new Process(sprintf('%s config -l', ProcessExecutor::escape($gitBin)));
        $cmd->run();
        if ($cmd->isSuccessful()) {
            $this->gitConfig = array();
            preg_match_all('{^([^=]+)=(.*)$}m', $cmd->getOutput(), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $this->gitConfig[$match[1]] = $match[2];
            }
            return $this->gitConfig;
        }
        return $this->gitConfig = array();
    }

    private function getDefaultPackageName()
    {
        $git = $this->getGitConfig();
        $name = basename(getcwd());
        $name = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
        $name = strtolower($name);
        if (isset($git['github.user'])) {
            $name = $git['github.user'] . '/' . $name;
        } elseif (!empty($_SERVER['USERNAME'])) {
            $name = $_SERVER['USERNAME'] . '/' . $name;
        } elseif (get_current_user()) {
            $name = get_current_user() . '/' . $name;
        } else {
            // package names must be in the format foo/bar
            $name = $name . '/' . $name;
        }
        return strtolower($name);
    }

    protected function runComposerCommand(...$args)
    {
        $finder = new PhpExecutableFinder();
        $phpPath = $finder->find();
        if (!$phpPath) {
            throw new \RuntimeException('Failed to locate PHP binary to execute '.implode(' ', $args));
        }
        $command = array_merge([$phpPath, realpath($_SERVER['argv'][0])], $args);
        $exec = implode(' ', array_map('escapeshellarg', $command));
        if (0 !== ($exitCode = $this->process->execute($exec))) {
            $this->io->writeError(sprintf('<error>Script %s returned with error code '.$exitCode.'</error>', $exec));
            throw new ScriptExecutionException('Error Output: '.$this->process->getErrorOutput(), $exitCode);
        }
    }

    protected function askQuestions()
    {
        $data = [];
        $name = $this->getDefaultPackageName();
        $data['package_name'] = $this->ask('Package name (<vendor>/<name>)', $name);
        list($data['project_organisation'], $data['project_name']) = explode('/', $data['package_name'], 2);
        $data['package_description'] = $this->ask('Description');
        $data['package_license'] = $this->ask('License (e.g. MIT,proprietary)');

        // Deprecated: custom questions to be removed and placed in the seed instead later
        $default = $this->formatDefaultNamespace($data['package_name']);
        $data['project_namespace'] = $this->ask('Source namespace', $default);
        $data['project_bin'] = $this->ask('Project binary name', strtolower($data['project_name']));

        return $data;
    }

    protected function convertTemplates(array $data)
    {
        $loader = new Twig_Loader_Filesystem(getcwd());
        $twig = new Twig_Environment($loader);

        foreach ($this->filterDirectories(getcwd()) as $file) {
            $content = $twig->render($file->getRelativePathname(), $data);
            preg_match('/(.*)\.seed\.twig$/', $file->getPathname(), $match);

            // rename to preserve existing permissions
            rename($file->getPathname(), $match[1]);
            file_put_contents($match[1], $content);
        }
        rename('bin/project_bin', 'bin/' . $data['project_bin']);
    }

    protected function updateJson(array $data)
    {
        $file = new JsonFile('composer.json');
        $config = $file->read();

        unset($config['require']['inviqa/composer-seeder-plugin']);
        unset($config['require-dev']['inviqa/composer-seeder-plugin']);
        unset($config['authors']);

        $config['name'] = $data['package_name'];
        if ($data['package_description']) {
            $config['description'] = $data['package_description'];
        } else {
            unset($config['description']);
        }
        if ($data['package_license']) {
            $config['license'] = $data['package_license'];
        } else {
            unset($config['license']);
        }

        $file->write($config);
    }

    protected function cleanupSkeleton()
    {
        // Deprecated: a custom action to be removed and placed in the seed instead later
        unlink('CHANGELOG.md');
    }

    public function run()
    {
        $data = $this->askQuestions();

        $this->convertTemplates($data);
        $this->updateJson($data);
        $this->cleanupSkeleton();
        $this->runComposerCommand('update');
    }
}
