<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Logger;

abstract class ErrorFormatter
{
    public static function formatError(\WP_Error $error): string
    {
        return \sprintf('%s: %s', \get_called_class(), $error->get_error_message());
    }
}
