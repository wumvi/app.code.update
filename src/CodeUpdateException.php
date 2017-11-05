<?php
declare(strict_types=1);

namespace CodeUpdate;

class CodeUpdateException extends \Exception
{
    public const ERROR_TO_EXECUTE = 1;
    public const EMPTY_ARGUMENTS = 2;
    public const PARSE_ARGUMENTS = 3;
    public const CONTAINER_NOT_FOUND = 4;
    public const CANT_CREATE_FOLDER = 5;
    public const ERROR_IN_DOWNLOADING = 6;
    public const FILE_IS_CORRUPTED = 7;
    public const INSPECT_IN_YANDEX = 8;
}
