<?php

namespace Filegator\Services\Auth;

/**
 * Auth backends where "logging in" means opening a connection to a storage server (FTP / SFTP)
 * instead of checking a username and password against a local list.
 */
interface ConnectionAuthInterface
{
    /** @param array $input protocol, host, port, username, password, root */
    public function connect(array $input): bool;

    /** Message that is safe to show the user after connect() returned false. */
    public function getError(): string;
}
