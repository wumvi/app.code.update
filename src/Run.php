<?php
declare(strict_types=1);

namespace CodeUpdate;

use \YandexDiskApi\Arguments\Disk\GetInfo;
use \YandexDiskApi\Disk;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use \DockerApi\Exec;
use \DockerApi\Containers;
use \DockerApi\Arguments\Exec\Prepare;
use GetOpt\GetOpt;
use GetOpt\Option;

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
     * @var string
     */
    private $service;

    /**
     * @var string
     */
    private $ref;

    /**
     * @var string
     */
    private $projectName;

    public function __construct()
    {
        $this->initArguments();
    }

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

        $option = new Option('s', 'service', GetOpt::REQUIRED_ARGUMENT);
        $option->setDescription('Name or ID of docker container');
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
        $this->service = (string)$getOpt->getOption('service');
        $this->ref = $getOpt->getOption('ref');
        $this->projectName = $getOpt->getOption('project');

        if (empty($this->token)) {
            throw new CodeUpdateException('Token is empty', CodeUpdateException::PARSE_ARGUMENTS);
        }

        if (empty($this->service)) {
            throw new CodeUpdateException('Service is empty', CodeUpdateException::PARSE_ARGUMENTS);
        }

        if (empty($this->ref)) {
            throw new CodeUpdateException('Ref is empty', CodeUpdateException::PARSE_ARGUMENTS);
        }

        if (empty($this->projectName)) {
            throw new CodeUpdateException('Project is empty', CodeUpdateException::PARSE_ARGUMENTS);
        }
    }

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

    public function run(): void
    {
        $dockerContainers = new Containers();

        try {
            $dockerContainers->inspect($this->service);
        } catch (\Exception $ex) {
            $msg = @json_decode($ex->getMessage());
            $msg = $msg ? $msg->message : $ex->getMessage();
            $msg = $msg ? $msg : 'Container with name "' . $this->service . '" not found';
            throw new CodeUpdateException($msg, CodeUpdateException::CONTAINER_NOT_FOUND);
        }

        $fileOnServer = $this->getFileOut();

        if (!is_readable($fileOnServer)) {
            $this->download($fileOnServer);
        }

        $dockerExec = new Exec();
        $arguments = [
            '-p ' . $this->projectName,
            '-f ' . $fileOnServer,
            '-r ' . $this->ref,
        ];
        $cmd = '/code.update.sh ' . implode(' ', $arguments);
        $prepareExec = new Prepare($this->service, $cmd);

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
        foreach($files as $file) {
            unlink($file);
        }
    }
}
