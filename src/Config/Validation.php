<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Jobs.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Daycry\Jobs\Config;

use Config\Validation as ConfigValidation;

class Validation extends ConfigValidation
{
    public array $queueData = [
        'queue'            => 'required|string',
        'job'             => 'required|string',
        'payload'           => 'required',
        'schedule'         => 'if_exist|permit_empty',
        'attempts'         => 'if_exist|is_natural',
        'callback'         => 'if_exist|permit_empty',
        'callback.url'     => 'if_exist|required_with[callback]|valid_url_strict[https]',
        'callback.options' => 'if_exist|required_with[callback]',
    ];

    public array $command = [
        'payload' => 'required|string',
    ];



    /*public array $url = [
        'verify'   => 'if_exist|permit_empty',
        'url'      => 'required|valid_url',
        'method'   => 'required|string',
        'dataType' => 'if_exist|required|string',
        'headers'  => 'if_exist|required',
        'body'     => 'if_exist|required',
    ];
    public array $classes = [
        'class'              => 'required|string',
        'method'             => 'required|string',
        'options'            => 'if_exist|permit_empty',
        'options.contructor' => 'if_exist|required_with[options]|required',
        'options.method'     => 'if_exist|required_with[options]|required',
    ];
    public array $command = [
        'command' => 'required|string',
        'options' => 'if_exist|permit_empty',
    ];
    public array $shell = [
        'command' => 'required|string',
        'options' => 'if_exist|permit_empty',
    ];
    public array $event = [
        'event'   => 'required|string',
        'options' => 'if_exist|permit_empty',
    ];*/
}
