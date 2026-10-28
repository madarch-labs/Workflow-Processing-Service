<?php

declare(strict_types=1);

/**
 * Generates a key for WPS_SECRETS_KEY (base64 of 32 random bytes):
 *   docker compose exec backend php bin/secrets-key.php
 */
echo base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()), PHP_EOL;
