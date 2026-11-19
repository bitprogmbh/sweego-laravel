<?php

use Bitprogmbh\SweegoLaravel\Bulk\BulkResult;

it('returns the swg uid of the recipient regardless of letter case', function () {
    $result = new BulkResult('tx-1', ['Anna@Example.com' => 'uid-anna']);

    expect($result->messageIdFor('anna@example.com'))->toBe('uid-anna')
        ->and($result->messageIdFor('ANNA@EXAMPLE.COM'))->toBe('uid-anna');
});

it('falls back to the transaction id when the recipient has no swg uid', function () {
    $result = new BulkResult('tx-1', ['anna@example.com' => 'uid-anna']);

    expect($result->messageIdFor('bob@example.com'))->toBe('tx-1');
});

it('has no message id without a swg uid and without a transaction id', function () {
    expect((new BulkResult)->messageIdFor('anna@example.com'))->toBeNull();
});

it('ignores swg uids that are not non-empty strings', function () {
    $result = new BulkResult(null, ['anna@example.com' => '', 'bob@example.com' => ['nested'], 'carla@example.com' => 'uid-carla']);

    expect($result->swgUids)->toBe(['carla@example.com' => 'uid-carla']);
});
