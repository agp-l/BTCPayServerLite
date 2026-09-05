## :warning: Warning

You are welcome to use this at your own risk. No liabilities, no warranty, check [LICENSE](LICENSE) file.

# ECDSA lib for PHP

Easy interface and to the point API for following curves and features:

* Secp256k1 (using GMP calculations)
* Secp256k1_RPC (using bitcoin's original [libsecp256k1](https://github.com/bitcoin-core/secp256k1) written in C lang,
  requires [furqansiddiqui/secp256k1-rpc](https://github.com/furqansiddiqui/secp256k1-rpc) RPC server)
* Built-in support for [RFC6979](https://www.rfc-editor.org/rfc/rfc6979) for generation of deterministic yet secure `k`
  nonce.

## Prerequisites

* PHP ^8.1
* ext-gmp
* ext-curl (for [furqansiddiqui/secp256k1-rpc](https://github.com/furqansiddiqui/secp256k1-rpc))

## Installation

`composer require furqansiddiqui/ecdsa-php`

# Documentation

## `Buffer` , `Bytes32`, `AbstractByteArray`

Uses [comely-io/buffer-php](https://github.com/comely-io/buffer-php) for data handling.

```php
// Create byte array from Base16/Hexadecimal string
$buffer = Buffer::fromBase16("hex-string"); 

// Serialize bytes in buffer to Hexadecimal
$buffer->toBase16();
```

## KeyPair

| Method          | Returns                 | Description                                                                                                                                                                |
|-----------------|-------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| __construct     | -                       | Requires [EllipticCurveInterface](#EllipticCurveInterface) and `AbstractByteArray` (private key) as arguments.                                                             |
| public          | [PublicKey](#PublicKey) | Creates and holds on to instance of [PublicKey](#PublicKey) for corresponding private key.                                                                                 |
| sign            | [Signature](#Signature) | Creates a signature. Depending on ECC curve passed in constructor, the returning Signature instance may or may not have recovery id set.                                   |
| signRecoverable | [Signature](#Signature) | Creates a recoverable signature. This signature will always contain a recovery id.                                                                                         |
| findRecoveryId  | int                     | Finds a recovery id for given [Signature](#Signature) and message hash.                                                                                                    |
| verify          | bool                    | Verifies a signature with given [Signature](#Signature) and message hash.                                                                                                  |
| verifyPublicKey | bool                    | Verifies a recoverable signature. It is possible to override recovery id directly in arguments for this method if [Signature](#Signature) does not have a recovery id set. |

## PublicKey

| Constructor | Arguments           | Description                                                              |
|-------------|---------------------|--------------------------------------------------------------------------|
| fromDER     | `AbstractByteArray` | Expects 65 byte long DER encoded public key starting with `\x04` prefix. |

```php
$pub = PublicKey::fromDER(Buffer::fromBase16("hex-string"))
```

| Method          | Returns  | Description                                                                                                                       |
|-----------------|----------|-----------------------------------------------------------------------------------------------------------------------------------|
| getUnCompressed | `Buffer` | Gets DER serialized uncompressed public key. 65 bytes long including prefix of `\x04`.                                            |
| getCompressed   | `Buffer` | Get DER serialized compressed public key. 33 bytes long including `\x02` or `\x03` prefix.                                        |
| compare         | `int`    | Compares with another [PublicKey](#PublicKey) instance. Returns `0` if identical OR a negative value if public keys do not match. |

## Signature

| Property   | Type                | Description                                                                                          |
|------------|---------------------|------------------------------------------------------------------------------------------------------|
| r          | `AbstractByteArray` | Signature value `r`. (readonly)                                                                      |
| s          | `AbstractByteArray` | Signature value `s`. (readonly)                                                                      |
| recoveryId | `int`               | Recovery ID (`v`) for signature. Defaults to `-1` when there is no recovery id available. (readonly) |

| Constructor | Arguments           | Description                                                                                                               |
|-------------|---------------------|---------------------------------------------------------------------------------------------------------------------------|
| fromDER     | `AbstractByteArray` | Unserializes a DER encoded signature            .                                                                         |
| fromCompact | `AbstractByteArray` | Unserializes a 65 byte compact signature where first byte is `v` (recovery id) followed by 32 bytes each for `r` and `s`. |

| Method  | Returns  | Description                                                                                                                      |
|---------|----------|----------------------------------------------------------------------------------------------------------------------------------|
| getDER  | `Buffer` | Returns a DER encoded signature starts with `\x30` byte.                                                                         |
| compare | `int`    | Compares with another [Signature](#Signature) instance. Returns `0` if identical OR a negative value if signatures do not match. |

## EllipticCurveInterface

| Method                        | Returns                 | Description                                                                                                                                                                                     |
|-------------------------------|-------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| validatePrivateKey            | `bool`                  | Validates a private key.                                                                                                                                                                        |
| generatePublicKey             | [PublicKey](#PublicKey) | Generates public key from given private key.                                                                                                                                                    |
| getPublicKeyFromCompressed    | [PublicKey](#PublicKey) | Retrieves uncompressed public key from given compressed variant. Accepted prefixes are `\x02` or `\x03`.                                                                                        |
| sign                          | [Signature](#Signature) | Creates a signature from private key and message hash. This method may or may not retrieve recovery id depending on ECC curve used. Therefore, recovery Id may have to be retrieved separately. |
| recoverPublicKeyFromSignature | [PublicKey](#PublicKey) | Recovers a public key from given [Signature](#Signature) and message hash. Can be used to retrieve recovery id in 1-4 iterations.                                                               |
| verify                        | `bool`                  | Verifies a signature with given [PublicKey](#PublicKey), [Signature](#Signature) and message hash.                                                                                              |

### Difference in Secp256k1 variants

| Method | Secp256k1_GMP                                                                                                                                                                              | Secp256k1_RPC                                               |
|--------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------|
| sign   | :no_entry_sign: Does not return recovery id with signature `r` and `s` values. It is recommended to use [KeyPair](#KeyPair) which provides `signRecoverable` and `findRecoveryId` methods. | :white_check_mark: Returns recovery id alongside signature. |



