<?php
declare(strict_types=1);

namespace CodeUpdate\Exception;

class UnpackCodeException extends \Exception
{
    public const ERROR_TO_OPEN_ZIP_FILE = 1;
    public const CAN_NOT_CREATE_FOLDER = 2;
    public const CAN_NOT_UNZIP_FILE = 3;
}
