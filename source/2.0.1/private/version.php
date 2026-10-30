<?php
declare(strict_types=1);
// Product identity and code version for the signed updater contract.
// Bump CODE_VERSION in the source tree before building a release; data stays
// compatible via schema_version in private/data/state.php.
const PRODUCT_ID = 'ocpeptide-admin';
const CODE_VERSION = '2.0.1';
const UPDATE_REQUIRES_PHP = '8.2';
const UPDATE_REQUIRES_EXTENSIONS = ['json', 'openssl', 'zip', 'curl'];
// Customer state schema this code can read and write (inclusive).
const SCHEMA_MIN = 2;
const SCHEMA_MAX = 2;
