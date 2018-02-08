<?php
declare(strict_types=1);

namespace CodeUpdate;

use CodeUpdate\Exception\CodeUpdateException;
use CodeUpdate\Exception\ExecCodeException;
use CodeUpdate\Exception\UnpackCodeException;
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
    private const PROJECT_DIR = '/www/%s/%s/';
    private const NGINX_CONFIG_FILE = '/www/conf/%s.conf';
    private const LAST_RUN_REF = '/www/run/%s.txt';
    private const BACKUP_EXT = '.bck';

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
    function checkContainersExists(): void
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
     * @param string $cmd
     *
     * @throws CodeUpdateException
     */
    function execCmdInContainer(string $cmd): void
    {
        $dockerExec = new Exec();

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
                $msg = vsprintf('Error to execute "%s" in "%s". Exit code %s', [$cmd, $containerName, $exitCode,]);
                throw new CodeUpdateException($msg, CodeUpdateException::ERROR_TO_EXECUTE);
            }
        }
    }

    /**
     * @throws CodeUpdateException
     * @throws UnpackCodeException
     * @throws ExecCodeException
     * @throws \Exception
     */
    public function run(): void
    {
        $this->checkContainersExists($this->containersName);

        $fileOnServer = $this->getFileOut();

        if (!is_readable($fileOnServer)) {
            $this->download($fileOnServer);
        }

        $this->unpackCode($fileOnServer);
        $this->prepareCode($fileOnServer);
    }

    private function getFileOut(): string
    {
        return str_replace(['{project}', '{ref}'], [$this->projectName, $this->ref], self::FILE_ON_SERVER);
    }

    /**
     * @param string $fileOnServer
     *
     * @throws ExecCodeException
     * @throws \Exception
     */
    function prepareCode($fileOnServer): void
    {
        $filesystem = new Filesystem();

        $projectDir = vsprintf(self::PROJECT_DIR, [$this->projectName, $this->ref]);
        $nginxConfFile = $projectDir . 'prod/nginx.conf';
        if (!is_readable($nginxConfFile)) {
            $filesystem->remove([$projectDir]);
            $msg = vsprintf("Nginx config '%s' not found", [$nginxConfFile,]);
            throw new ExecCodeException($msg, ExecCodeException::NGINX_CONFIG_NOT_FOUND);
        }

        $oldNginxConfigFile = vsprintf(self::NGINX_CONFIG_FILE, [$this->projectName,]);
        if ($oldNginxConfigFile) {
            rename($oldNginxConfigFile, $oldNginxConfigFile . self::BACKUP_EXT);
        }

        $newNginxConfigFile = vsprintf(self::NGINX_CONFIG_FILE, [$this->projectName,]);
        $fw = fopen($newNginxConfigFile, 'w');
        if (!$fw) {
            rename($oldNginxConfigFile . self::BACKUP_EXT, $oldNginxConfigFile);
            $msg = vsprintf("Can not create '%s'", [$newNginxConfigFile,]);
            throw new ExecCodeException($msg, ExecCodeException::CAN_NOT_CREATE_NGIXN_CONFIG);
        }

        $newNginxConfData = file_get_contents($nginxConfFile);
        $newNginxConfData = str_replace('{project-path}', $projectDir, $newNginxConfData);

        if (!fwrite($fw, $newNginxConfData)) {
            $filesystem->remove([$newNginxConfigFile, $projectDir]);
            rename($oldNginxConfigFile . self::BACKUP_EXT, $oldNginxConfigFile);
            $msg = vsprintf("Error to write data in '%s'", [$newNginxConfigFile,]);
            throw new ExecCodeException($msg, ExecCodeException::CAN_NOT_CREATE_NGIXN_CONFIG);
        }

        try {
            $arguments = ['-p ' . $this->projectName, '-r ' . $this->ref,];
            $cmd = '/code.test.sh ' . implode(' ', $arguments);
            $this->execCmdInContainer($cmd);
        } catch (\Exception $ex) {
            rename($oldNginxConfigFile . self::BACKUP_EXT, $oldNginxConfigFile);

            $filesystem->remove($projectDir);
            throw $ex;
        }

        try {
            $arguments = ['-p ' . $this->projectName, '-f ' . $fileOnServer, '-r ' . $this->ref,];
            $cmd = '/code.update.sh ' . implode(' ', $arguments);
            $this->execCmdInContainer($cmd);
        } catch (\Exception $ex) {
            rename($oldNginxConfigFile . self::BACKUP_EXT, $oldNginxConfigFile);
            $filesystem->remove($projectDir);

            throw $ex;
        }

        $refRunFile = vsprintf(self::LAST_RUN_REF, [$this->projectName,]);
        $lastRef = is_readable($refRunFile) ? trim(file_get_contents($refRunFile)) : '';

        if ($lastRef && $lastRef !== $this->ref) {
            $oldProjectDir = vsprintf(self::PROJECT_DIR, [$this->projectName, $lastRef]);
            $filesystem->remove([$oldProjectDir, $oldNginxConfigFile]);
        }

        $filesystem->mkdir(dirname($refRunFile));
        file_put_contents($refRunFile, $this->ref);
    }

    /**
     * @param string $zipFile
     *
     * @throws UnpackCodeException
     */
    function unpackCode(string $zipFile): void
    {
        $zip = new \ZipArchive();
        if (!$zip->open($zipFile) === true) {
            $msg = vsprintf("Error to open '%s'", [$zipFile,]);
            throw new UnpackCodeException($msg, UnpackCodeException::ERROR_TO_OPEN_ZIP_FILE);
        }

        $filesystem = new Filesystem();
        $projectDir = vsprintf(self::PROJECT_DIR, [$this->projectName, $this->ref]);
        try {
            $filesystem->mkdir($projectDir);
        } catch (\Exception $ex) {
            $msg = vsprintf('Can not create folder "%s"', [$projectDir,]);
            throw new UnpackCodeException($msg, UnpackCodeException::CAN_NOT_CREATE_FOLDER);
        }

        $filesystem->remove($projectDir);
        if (!$zip->extractTo($projectDir)) {
            $msg = vsprintf('Can not unzip file "%s" in folder "%s"', [$zipFile, $projectDir,]);
            throw new UnpackCodeException($msg, UnpackCodeException::CAN_NOT_UNZIP_FILE);
        }
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
