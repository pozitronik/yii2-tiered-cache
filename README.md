# Yii2 Tiered Cache

[![Tests](https://github.com/pozitronik/yii2-tiered-cache/actions/workflows/tests.yml/badge.svg)](https://github.com/pozitronik/yii2-tiered-cache/actions/workflows/tests.yml)
[![Codecov](https://codecov.io/gh/pozitronik/yii2-tiered-cache/branch/master/graph/badge.svg)](https://codecov.io/gh/pozitronik/yii2-tiered-cache)
[![Packagist Version](https://img.shields.io/packagist/v/beeline/yii2-tiered-cache)](https://packagist.org/packages/beeline/yii2-tiered-cache)
[![Packagist License](https://img.shields.io/packagist/l/beeline/yii2-tiered-cache)](https://packagist.org/packages/beeline/yii2-tiered-cache)
[![Packagist Downloads](https://img.shields.io/packagist/dt/beeline/yii2-tiered-cache)](https://packagist.org/packages/beeline/yii2-tiered-cache)

Многоуровневый компонент кеширования для Yii2 с защитой через circuit breaker и автоматическим переключением слоев.

## Возможности

- **Многоуровневая архитектура кеша**: Несколько слоев кеша (L1, L2, L3, ...) с автоматическим переключением при отказах
- **Защита через circuit breaker**: Каждый слой защищен circuit breaker для предотвращения каскадных сбоев
- **Гибкие стратегии записи**: Сквозная запись (во все слои) или запись в первый слой
- **Интеллектуальное восстановление**: Автоматическое заполнение слоев после восстановления
- **Управление TTL**: Переопределение TTL для каждого слоя для оптимального использования ресурсов
- **Поддержка зависимостей Yii2**: Полная поддержка TagDependency и других механизмов зависимостей Yii2
- **Обратная совместимость**: Режим авто-обертки для бесшовной миграции со стандартного кеша Yii2

## Установка

```bash
composer require beeline/yii2-tiered-cache
```

## Требования

- PHP 8.4 или выше
- Yii2 2.0.45 или выше (для совместимости с PHP 8.x)

## Базовое использование

### Конфигурация

```php
'cache' => [
    'class' => \Beeline\TieredCache\Cache\TieredCache::class,
    'layers' => [
        [
            'cache' => ['class' => \yii\caching\ApcCache::class, 'useApcu' => true],
            'ttl' => 300,  // 5 минут для L1
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

### Стандартные операции с кешем

```php
// Установить значение
Yii::$app->cache->set('key', 'value', 3600);

// Получить значение
$value = Yii::$app->cache->get('key');

// Удалить значение
Yii::$app->cache->delete('key');

// Очистить все слои
Yii::$app->cache->flush();
```

### Использование с TagDependency

```php
use yii\caching\TagDependency;

// Установить с зависимостью
Yii::$app->cache->set('user:123', $userData, 3600,
    new TagDependency(['tags' => ['user-cache', 'user-123']])
);

// Инвалидировать по тегу
TagDependency::invalidate(Yii::$app->cache, 'user-cache');
```

## Параметры конфигурации

### Стратегии записи

**WRITE_THROUGH** (по умолчанию) - Запись во все доступные слои:

```php
'writeStrategy' => \Beeline\TieredCache\Cache\TieredCache::WRITE_THROUGH,
```

**WRITE_FIRST** - Запись только в первый доступный слой:

```php
'writeStrategy' => \Beeline\TieredCache\Cache\TieredCache::WRITE_FIRST,
```

### Стратегии восстановления

**RECOVERY_POPULATE** (по умолчанию) - Активное заполнение восстановленных слоев:

```php
'recoveryStrategy' => \Beeline\TieredCache\Cache\TieredCache::RECOVERY_POPULATE,
```

**RECOVERY_NATURAL** - Естественное заполнение слоев:

```php
'recoveryStrategy' => \Beeline\TieredCache\Cache\TieredCache::RECOVERY_NATURAL,
```

### Конфигурация Circuit Breaker

```php
'layers' => [
    [
        'cache' => ['class' => \yii\caching\RedisCache::class, 'redis' => 'redis'],
        'circuitBreaker' => [
            'failureThreshold' => 0.5,    // Открыть при 50% отказов
            'windowSize' => 10,            // Отслеживать последние 10 запросов
            'timeout' => 30,               // Повторить попытку через 30 секунд
            'successThreshold' => 1,       // Закрыть после 1 успеха
        ],
    ],
],
```

### Переопределение TTL для слоя

```php
'layers' => [
    [
        'cache' => ['class' => \yii\caching\ApcCache::class, 'useApcu' => true],
        'ttl' => 300,  // Переопределение: максимум 5 минут для этого слоя
    ],
],
```

## Продвинутое использование

### Собственный Circuit Breaker

```php
'defaultBreakerClass' => \Beeline\TieredCache\Resilience\CircuitBreaker::class,
```

### Строгий режим

Отклонение необернутых значений для согласованности формата данных:

```php
'strictMode' => true,
```

### Мониторинг состояния слоев

```php
$status = Yii::$app->cache->getLayerStatus();

foreach ($status as $layer) {
    echo "Слой {$layer['index']}: {$layer['class']}\n";
    echo "Состояние: {$layer['state']}\n";  // closed, open, half_open
    echo "Отказы: {$layer['stats']['failures']}\n";
}
```

### Ручное управление Circuit Breaker

```php
// Принудительно отключить слой (тестирование/обслуживание)
Yii::$app->cache->forceLayerOpen(1);

// Принудительно включить слой
Yii::$app->cache->forceLayerClose(1);

// Сбросить все circuit breaker
Yii::$app->cache->resetCircuitBreakers();
```

## Архитектура

### Принцип работы

**Операции чтения (get)**:
1. Проверка доступности первого слоя (circuit breaker)
2. Попытка чтения из первого доступного слоя
3. При успехе: опционально заполнить верхние слои (RECOVERY_POPULATE)
4. При отказе: попытка следующего слоя
5. Запись результата в circuit breaker

**Операции записи (set)**:
- **WRITE_THROUGH**: Запись во все доступные слои
- **WRITE_FIRST**: Запись только в первый доступный слой

**Операции удаления**:
- Всегда удаление из всех слоев (независимо от стратегии записи)

### Состояния Circuit Breaker

**CLOSED**: Нормальная работа, запросы проходят

**OPEN**: Слишком много отказов, запросы блокируются

**HALF_OPEN**: Тестирование восстановления, ограниченное количество запросов

### Отказоустойчивость

При отказе слоя кеша:
1. Circuit breaker фиксирует отказ
2. После N отказов circuit открывается (слой пропускается)
3. Запросы автоматически направляются к следующему доступному слою
4. После таймаута circuit переходит в состояние HALF_OPEN
5. Успешный запрос закрывает circuit (слой восстановлен)

## Преимущества

- **Высокая доступность**: Автоматическое переключение предотвращает недоступность кеша
- **Производительность**: Нет ожидания таймаутов при известных отказах
- **Плавная деградация**: Система работает даже при отказе слоев кеша
- **Быстрое восстановление**: Автоматическое обнаружение и восстановление отказавших слоев
- **Оптимизация ресурсов**: TTL для каждого слоя для эффективного использования памяти

## Тестирование

```bash
# Установка зависимостей
composer install

# Запуск тестов
vendor/bin/phpunit
```

## Лицензия

GNU Lesser General Public License 3.0

## Ссылки

- [GitHub Repository](https://github.com/beeline/yii2-tiered-cache)
- [Issue Tracker](https://github.com/beeline/yii2-tiered-cache/issues)
- [Yii2 Framework](https://www.yiiframework.com/)
