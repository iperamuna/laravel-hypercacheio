<?php

uses(\Iperamuna\Hypercacheio\Tests\TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check whether values match
| expectations. Pest provides a powerful set of expectations for this.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
