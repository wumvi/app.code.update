<?php
declare(strict_types=1);

namespace CodeUpdate\Exception;

class ExecCodeException extends \Exception
{
    public const NGINX_CONFIG_NOT_FOUND = 1;
    public const CAN_NOT_CREATE_NGIXN_CONFIG = 2;
    public const ERROR_IN_CHECKING = 3;
}
