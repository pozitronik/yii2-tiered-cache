# Yii2 Tiered Cache

Multi-tiered caching component for Yii2 with circuit breaker protection and automatic failover.

## Features

- **Multi-tier cache architecture**: Multiple cache layers (L1, L2, L3, ...) with automatic failover
- **Circuit breaker protection**: Each layer protected by circuit breaker to prevent cascading failures
- **Flexible write strategies**: Write-through (all layers) or write-first (fastest layer only)
- **Intelligent recovery**: Automatic layer repopulation after failures
- **TTL management**: Per-layer TTL overrides for optimal resource usage
- **Yii2 dependency support**: Full support for TagDependency and other Yii2 cache dependencies
- **Backward compatibility**: Auto-wrap mode for seamless migration from standard Yii2 cache

## Installation

```bash
composer require beeline/yii2-tiered-cache
```

## Requirements

- PHP 8.4 or higher
- Yii2 2.0 or higher

## Basic Usage

### Configuration

```php
'cache' => [
    'class' => \Beeline\TieredCache\Cache\TieredCache::class,
    'layers' => [
        [
            'cache' => ['class' => \yii\caching\ApcCache::class, 'useApcu' => true],
            'ttl' => 300,  // 5 minutes for L1
        ],
        [
            'cache' => ['class' => \yii\caching\RedisCache::class, 'redis' => 'redis'],
        ],
        [
            'cache' => ['class' => \yii\caching\DbCache::class, 'db' => 'db'],
        ],
    ],
],
```

### Standard Cache Operations

```php
// Set value
Yii::$app->cache->set('key', 'value', 3600);

// Get value
$value = Yii::$app->cache->get('key');

// Delete value
Yii::$app->cache->delete('key');

// Flush all layers
Yii::$app->cache->flush();
```

### With TagDependency

```php
use yii\caching\TagDependency;

// Set with dependency
Yii::$app->cache->set('user:123', $userData, 3600,
    new TagDependency(['tags' => ['user-cache', 'user-123']])
);

// Invalidate by tag
TagDependency::invalidate(Yii::$app->cache, 'user-cache');
```

## Configuration Options

### Write Strategies

**WRITE_THROUGH** (default) - Write to all available layers:

```php
'writeStrategy' => \Beeline\TieredCache\Cache\TieredCache::WRITE_THROUGH,
```

**WRITE_FIRST** - Write only to first available layer:

```php
'writeStrategy' => \Beeline\TieredCache\Cache\TieredCache::WRITE_FIRST,
```

### Recovery Strategies

**RECOVERY_POPULATE** (default) - Actively populate recovered layers:

```php
'recoveryStrategy' => \Beeline\TieredCache\Cache\TieredCache::RECOVERY_POPULATE,
```

**RECOVERY_NATURAL** - Let layers fill naturally:

```php
'recoveryStrategy' => \Beeline\TieredCache\Cache\TieredCache::RECOVERY_NATURAL,
```

### Circuit Breaker Configuration

```php
'layers' => [
    [
        'cache' => ['class' => \yii\caching\RedisCache::class, 'redis' => 'redis'],
        'circuitBreaker' => [
            'failureThreshold' => 0.5,    // Open circuit at 50% failure rate
            'windowSize' => 10,            // Track last 10 requests
            'timeout' => 30,               // Retry after 30 seconds
            'successThreshold' => 1,       // Close circuit after 1 success
        ],
    ],
],
```

### Per-Layer TTL Override

```php
'layers' => [
    [
        'cache' => ['class' => \yii\caching\ApcCache::class, 'useApcu' => true],
        'ttl' => 300,  // Override: max 5 minutes for this layer
    ],
],
```

## Advanced Usage

### Custom Circuit Breaker

```php
'defaultBreakerClass' => \Beeline\TieredCache\Resilience\CircuitBreaker::class,
```

### Strict Mode

Reject non-wrapped values for data format consistency:

```php
'strictMode' => true,
```

### Monitoring Layer Status

```php
$status = Yii::$app->cache->getLayerStatus();

foreach ($status as $layer) {
    echo "Layer {$layer['index']}: {$layer['class']}\n";
    echo "State: {$layer['state']}\n";  // closed, open, half_open
    echo "Failures: {$layer['stats']['failures']}\n";
}
```

### Manual Circuit Breaker Control

```php
// Force layer offline (testing/maintenance)
Yii::$app->cache->forceLayerOpen(1);

// Force layer online
Yii::$app->cache->forceLayerClose(1);

// Reset all circuit breakers
Yii::$app->cache->resetCircuitBreakers();
```

## Architecture

### How It Works

**Read Operations (get)**:
1. Check first layer availability (circuit breaker)
2. Attempt read from first available layer
3. On success: optionally populate upper layers (RECOVERY_POPULATE)
4. On failure: try next layer
5. Record result in circuit breaker

**Write Operations (set)**:
- **WRITE_THROUGH**: Write to all available layers
- **WRITE_FIRST**: Write to first available layer only

**Delete Operations**:
- Always delete from all layers (regardless of write strategy)

### Circuit Breaker States

**CLOSED**: Normal operation, requests pass through

**OPEN**: Too many failures, requests blocked

**HALF_OPEN**: Testing recovery, limited requests allowed

### Fault Tolerance

When a cache layer fails:
1. Circuit breaker records failure
2. After N failures, circuit opens (layer skipped)
3. Requests automatically routed to next available layer
4. After timeout, circuit transitions to HALF_OPEN
5. Successful request closes circuit (layer recovered)

## Benefits

- **High availability**: Automatic failover prevents cache outages
- **Performance**: No waiting for timeouts on known failures
- **Graceful degradation**: System works even when cache layers fail
- **Fast recovery**: Automatic detection and restoration of failed layers
- **Resource optimization**: Per-layer TTL for efficient memory usage

## Testing

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit
```

## License

GNU General Public License v3.0 or later. See LICENSE file for details.

## Links

- [GitHub Repository](https://github.com/beeline/yii2-tiered-cache)
- [Issue Tracker](https://github.com/beeline/yii2-tiered-cache/issues)
- [Yii2 Framework](https://www.yiiframework.com/)
