<?php
declare(strict_types=1);

namespace CodeUpdate;

use DockerApi\Arguments\Exec\Prepare;
use DockerApi\Containers;
use DockerApi\Exec;
use GetOpt\GetOpt;
use GetOpt\Option;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use YandexDiskApi\Arguments\Disk\GetInfo;
use YandexDiskApi\Disk;

class Run
{
    private const TOKEN_LENGTH = 39;

    private const FILE_ON_SERVER = '/update/{project}/{ref}.zip';

    private const MAX_FILE_IN_FOLDER = 6;

    /**
     * @var string
     */
    private $token;

    /**
     * @var string[]
     */
    private $containersName;

    /**
     * @var string
     */
    private $ref;

    /**
     * @var string
     */
    private $projectName;

    /**
     * Run constructor.
     *
     * @throws CodeUpdateException
     */
    public function __construct()
    {
        $this->initArguments();
    }

    /**
     * @throws CodeUpdateException
     */
    private function initArguments()
    {
        $getOpt = new GetOpt();

        $optionHelp = new Option(null, 'help', GetOpt::NO_ARGUMENT);
        $optionHelp->setDescription('This help');
        $getOpt->addOption($optionHelp);

        $option = new Option('t', 'token', GetOpt::REQUIRED_ARGUMENT);
        $option->setDescription('Yandex Disk Api token');
        $option->setValidation(function ($value) {
            return strlen(trim($value)) === self::TOKEN_LENGTH;
        });
        $getOpt->addOption($option);

        $option = new Option('r', 'ref', GetOpt::REQUIRED_ARGUMENT);
        $option->setDescription('Tag or branch name');
        $getOpt->addOption($option);

        $option = new Option('p', 'project', GetOpt::REQUIRED_ARGUMENT);
        $option->setDescription('Project name');
        $getOpt->addOption($option);

        $option = new Option('s', 'service', GetOpt::MULTIPLE_ARGUMENT);
        $option->setDescription('Container name or id');
        $getOpt->addOption($option);

        try {
            $getOpt->process();
        } catch (\Exception $ex) {
            throw new CodeUpdateException($ex->getMessage(), CodeUpdateException::PARSE_ARGUMENTS);
        }

        $options = $getOpt->getOption('help');
        if ($options) {
            echo $getOpt->getHelpText();
            exit;
        }

        $this->token = $getOpt->getOption('token');
        $this->containersName = $getOpt->getOption('service');
        $this->ref = $getOpt->getOption('ref');
        $this->projectName = $getOpt->getOption('project');

        if (empty($this->token)) {
            throw new CodeUpdateException('Yandex token is empty', CodeUpdateException::PARSE_ARGUMENTS);
        }

        if (empty($this->ref)) {
            throw new CodeUpdateException('Ref is empty', CodeUpdateException::PARSE_ARGUMENTS);
        }

        if (empty($this->projectName)) {
            throw new CodeUpdateException('Project is empty', CodeUpdateException::PARSE_ARGUMENTS);
        }
    }

    /**
     * @param string $fileOnServer
     *
     * @throws CodeUpdateException
     */
    private function download(string $fileOnServer): void
    {
        $fileOnYandexDisk = 'builds/' . $this->projectName . '/' . $this->ref . '.zip';
        $disk = new Disk($this->token);

        try {
            $info = new GetInfo($fileOnYandexDisk);
            $info = $disk->getInfo($info);
            $md5 = $info->getMd5();
        } catch (\Exception $ex) {
            throw new CodeUpdateException($ex->getMessage(), CodeUpdateException::INSPECT_IN_YANDEX);
        }

        $fs = new Filesystem();
        try {
            $fs->mkdir(dirname($fileOnServer));
        } catch (IOExceptionInterface  $ex) {
            throw new CodeUpdateException('Error to create ' . $ex->getPath(), CodeUpdateException::CANT_CREATE_FOLDER);
        }

        try {
            $disk->download($fileOnYandexDisk, $fileOnServer);
        } catch (\Exception  $ex) {
            throw new CodeUpdateException($ex->getMessage(), CodeUpdateException::ERROR_IN_DOWNLOADING);
        }

        if (md5_file($fileOnServer) !== $md5) {
            throw new CodeUpdateException('File is corrupted', CodeUpdateException::FILE_IS_CORRUPTED);
        }

        $this->clearFile();
    }

    /**
     * @throws CodeUpdateException
     */
    function checkContainersExists()
    {
        $dockerContainers = new Containers();

        foreach ($this->containersName as $containerName) {
            try {
                $dockerContainers->inspect($containerName);
            } catch (\Exception $ex) {
                $msg = @json_decode($ex->getMessage());
                $msg = $msg ? $msg->message : $ex->getMessage();
                $msg = $msg ? $msg : 'Container "' . $containerName . '" not found';
                throw new CodeUpdateException($msg, CodeUpdateException::CONTAINER_NOT_FOUND);
            }
        }
    }

    /**
     * @param string $fileOnServer
     *
     * @throws CodeUpdateException
     */
    function execCmdInContainer(string $fileOnServer)
    {
        $dockerExec = new Exec();
        $arguments = [
            '-p ' . $this->projectName,
            '-f ' . $fileOnServer,
            '-r ' . $this->ref,
        ];
        $cmd = '/code.update.sh ' . implode(' ', $arguments);

        foreach ($this->containersName as $containerName) {
            $prepareExec = new Prepare($containerName, $cmd);

            try {
                $startId = $dockerExec->prepare($prepareExec);
                $dockerExec->start($startId);
                $exitCode = $dockerExec->inspect($startId)->getExitCode();
            } catch (\Exception $ex) {
                $msg = @json_decode($ex->getMessage());
                $msg = $msg ? $msg->message : $ex->getMessage();
                throw new CodeUpdateException($msg, CodeUpdateException::ERROR_TO_EXECUTE);
            }

            if ($exitCode !== 0) {
                throw new CodeUpdateException(
                    'Error to execute ' . $cmd . '. Exit code ' . $exitCode,
                    CodeUpdateException::ERROR_TO_EXECUTE
                );
            }
        }
    }

    /**
     * @throws CodeUpdateException
     */
    public function run(): void
    {
        if ($this->containersName) {
            $this->checkContainersExists($this->containersName);
        }

        $fileOnServer = $this->getFileOut();

        if (!is_readable($fileOnServer)) {
            $this->download($fileOnServer);
        }

        if ($this->containersName) {
            $this->execCmdInContainer($fileOnServer);
        }
    }

    private function getFileOut(): string
    {
        return str_replace(['{project}', '{ref}'], [$this->projectName, $this->ref], self::FILE_ON_SERVER);
    }

    private function clearFile(): void
    {
        $folder = dirname($this->getFileOut());
        $files = glob($folder . '/*.zip', GLOB_BRACE);
        array_multisort(array_map('filemtime', $files), SORT_DESC, $files);

        $files = array_slice($files, self::MAX_FILE_IN_FOLDER);
        foreach ($files as $file) {
            unlink($file);
        }
    }
}
