<?php
declare(strict_types=1);

namespace Beeline\TieredCache\Tests;

use Beeline\TieredCache\Cache\TieredCache;
use Codeception\Test\Unit;
use yii\caching\ArrayCache;
use yii\caching\TagDependency;

/**
 * Комплексный набор тестов для проверки совместимости TieredCache с TagDependency
 *
 * Набор покрывает все сценарии использования TagDependency:
 * - Базовая инвалидация по тегам
 * - Множественные теги для одного значения
 * - Множественные значения с одним тегом
 * - Работа с разными стратегиями записи
 * - Работа с разными стратегиями восстановления
 * - Согласованность тегов между слоями
 * - Граничные случаи и edge cases
 */
class TieredCacheTagDependencyTest extends Unit
{
    /**
     * Сценарий: Базовая инвалидация одного значения по одному тегу
     *
     * Шаги:
     * 1. Сохранить значение с тегом 'tag-a'
     * 2. Проверить что значение доступно
     * 3. Инвалидировать тег 'tag-a'
     * 4. Проверить что значение стало недоступно
     */
    public function testBasicSingleTagInvalidation(): void
    {
        $cache = $this->createTieredCache();

        // Сохраняем значение с тегом
        $cache->set('key1', 'value1', 3600, new TagDependency(['tags' => 'tag-a']));

        // Значение должно быть доступно
        self::assertEquals('value1', $cache->get('key1'), 'Value should be accessible before invalidation');

        // Инвалидируем тег
        TagDependency::invalidate($cache, 'tag-a');

        // Значение должно стать недоступным
        self::assertFalse($cache->get('key1'), 'Value should be invalidated after tag invalidation');
    }

    /**
     * Сценарий: Инвалидация нескольких значений одним тегом
     *
     * Проверяет что один тег может инвалидировать множество значений.
     */
    public function testMultipleValuesWithSingleTag(): void
    {
        $cache = $this->createTieredCache();

        // Сохраняем три значения с одним тегом
        $cache->set('user_1_profile', 'Profile 1', 3600, new TagDependency(['tags' => 'user-cache']));
        $cache->set('user_1_settings', 'Settings 1', 3600, new TagDependency(['tags' => 'user-cache']));
        $cache->set('user_1_preferences', 'Prefs 1', 3600, new TagDependency(['tags' => 'user-cache']));

        // Все значения доступны
        self::assertEquals('Profile 1', $cache->get('user_1_profile'));
        self::assertEquals('Settings 1', $cache->get('user_1_settings'));
        self::assertEquals('Prefs 1', $cache->get('user_1_preferences'));

        // Инвалидируем один тег
        TagDependency::invalidate($cache, 'user-cache');

        // Все значения должны стать недоступными
        self::assertFalse($cache->get('user_1_profile'), 'Profile should be invalidated');
        self::assertFalse($cache->get('user_1_settings'), 'Settings should be invalidated');
        self::assertFalse($cache->get('user_1_preferences'), 'Preferences should be invalidated');
    }

    /**
     * Сценарий: Значение с несколькими тегами (массив тегов)
     *
     * Проверяет что значение может зависеть от нескольких тегов,
     * и инвалидация любого из них делает значение недоступным.
     */
    public function testValueWithMultipleTags(): void
    {
        $cache = $this->createTieredCache();

        // Сохраняем значение с тремя тегами
        $cache->set('composite_data', 'Data 123', 3600,
            new TagDependency(['tags' => ['tag-a', 'tag-b', 'tag-c']])
        );

        // Значение доступно
        self::assertEquals('Data 123', $cache->get('composite_data'));

        // Инвалидируем только tag-b
        TagDependency::invalidate($cache, 'tag-b');

        // Значение должно стать недоступным (один из тегов инвалидирован)
        self::assertFalse($cache->get('composite_data'),
            'Value should be invalidated when any of its tags is invalidated');
    }

    /**
     * Сценарий: Селективная инвалидация - разные значения с разными тегами
     *
     * Проверяет что инвалидация одного тега не затрагивает значения с другими тегами.
     */
    public function testSelectiveInvalidation(): void
    {
        $cache = $this->createTieredCache();

        // Сохраняем значения с разными тегами
        $cache->set('key_a', 'value_a', 3600, new TagDependency(['tags' => 'tag-a']));
        $cache->set('key_b', 'value_b', 3600, new TagDependency(['tags' => 'tag-b']));
        $cache->set('key_c', 'value_c', 3600, new TagDependency(['tags' => 'tag-c']));

        // Инвалидируем только tag-b
        TagDependency::invalidate($cache, 'tag-b');

        // Проверяем результаты
        self::assertEquals('value_a', $cache->get('key_a'), 'key_a with tag-a should remain valid');
        self::assertFalse($cache->get('key_b'), 'key_b with tag-b should be invalidated');
        self::assertEquals('value_c', $cache->get('key_c'), 'key_c with tag-c should remain valid');
    }

    /**
     * Сценарий: Инвалидация нескольких тегов одновременно
     *
     * Проверяет массовую инвалидацию тегов.
     */
    public function testBatchTagInvalidation(): void
    {
        $cache = $this->createTieredCache();

        $cache->set('key_a', 'value_a', 3600, new TagDependency(['tags' => 'tag-a']));
        $cache->set('key_b', 'value_b', 3600, new TagDependency(['tags' => 'tag-b']));
        $cache->set('key_c', 'value_c', 3600, new TagDependency(['tags' => 'tag-c']));

        // Инвалидируем несколько тегов сразу
        TagDependency::invalidate($cache, ['tag-a', 'tag-c']);

        // Проверяем результаты
        self::assertFalse($cache->get('key_a'), 'key_a should be invalidated');
        self::assertEquals('value_b', $cache->get('key_b'), 'key_b should remain valid');
        self::assertFalse($cache->get('key_c'), 'key_c should be invalidated');
    }

    /**
     * Сценарий: Повторная запись значения после инвалидации
     *
     * Проверяет что после инвалидации можно снова записать значение с тем же тегом.
     */
    public function testRewriteAfterInvalidation(): void
    {
        $cache = $this->createTieredCache();

        // Первая запись
        $cache->set('key1', 'old_value', 3600, new TagDependency(['tags' => 'tag-x']));
        self::assertEquals('old_value', $cache->get('key1'));

        // Инвалидация
        TagDependency::invalidate($cache, 'tag-x');
        self::assertFalse($cache->get('key1'));

        // Повторная запись с тем же тегом
        $cache->set('key1', 'new_value', 3600, new TagDependency(['tags' => 'tag-x']));
        self::assertEquals('new_value', $cache->get('key1'),
            'Should be able to set value with same tag after invalidation');
    }

    /**
     * Сценарий: Значения без тегов не затрагиваются инвалидацией
     *
     * Проверяет что значения без TagDependency остаются доступными.
     */
    public function testValuesWithoutTagsAreNotAffected(): void
    {
        $cache = $this->createTieredCache();

        // Значение с тегом
        $cache->set('tagged', 'tagged_value', 3600, new TagDependency(['tags' => 'tag-a']));

        // Значение без тега
        $cache->set('untagged', 'untagged_value', 3600);

        // Инвалидация тега
        TagDependency::invalidate($cache, 'tag-a');

        // Проверяем
        self::assertFalse($cache->get('tagged'), 'Tagged value should be invalidated');
        self::assertEquals('untagged_value', $cache->get('untagged'),
            'Untagged value should remain accessible');
    }

    /**
     * Сценарий: WRITE_THROUGH стратегия - теги во всех слоях
     *
     * Проверяет что с WRITE_THROUGH тег timestamps записываются во все слои.
     */
    public function testWriteThroughStrategyTagConsistency(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();
        $l3 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
                ['cache' => $l3],
            ],
            'writeStrategy' => TieredCache::WRITE_THROUGH,
        ]);

        // Сохраняем значение с тегом
        $cache->set('test_key', 'test_value', 3600, new TagDependency(['tags' => 'test-tag']));

        // Инвалидируем тег
        TagDependency::invalidate($cache, 'test-tag');

        // Проверяем что tag timestamp есть во всех слоях
        $tagKey = $cache->buildKey([TagDependency::class, 'test-tag']);

        self::assertNotFalse($l1->get($tagKey), 'Tag timestamp should exist in L1');
        self::assertNotFalse($l2->get($tagKey), 'Tag timestamp should exist in L2');
        self::assertNotFalse($l3->get($tagKey), 'Tag timestamp should exist in L3');

        // Значение должно быть инвалидировано
        self::assertFalse($cache->get('test_key'), 'Value should be invalidated');
    }

    /**
     * Сценарий: WRITE_FIRST стратегия - потенциальная несогласованность тегов
     *
     * Проверяет работу тегов с WRITE_FIRST стратегией.
     * ВАЖНО: это может выявить проблемы с согласованностью.
     */
    public function testWriteFirstStrategyTagBehavior(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
            ],
            'writeStrategy' => TieredCache::WRITE_FIRST,
        ]);

        // Сохраняем значение с тегом
        $cache->set('test_key', 'test_value', 3600, new TagDependency(['tags' => 'test-tag']));

        // Инвалидируем тег (timestamp записывается только в L1)
        TagDependency::invalidate($cache, 'test-tag');

        // Значение должно быть инвалидировано
        self::assertFalse($cache->get('test_key'),
            'Value should be invalidated even with WRITE_FIRST');
    }

    /**
     * Сценарий: RECOVERY_POPULATE с тегами
     *
     * Проверяет что при восстановлении значения из нижнего слоя
     * теги продолжают работать корректно.
     */
    public function testRecoveryPopulateWithTags(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
            ],
            'writeStrategy' => TieredCache::WRITE_THROUGH,
            'recoveryStrategy' => TieredCache::RECOVERY_POPULATE,
        ]);

        // Сохраняем значение с тегом
        $cache->set('test_key', 'test_value', 3600, new TagDependency(['tags' => 'test-tag']));

        // Удаляем из L1 для имитации cache miss
        $l1->flush();

        // Читаем - должно восстановиться из L2 в L1
        self::assertEquals('test_value', $cache->get('test_key'),
            'Value should be recovered from L2');

        // Инвалидируем тег
        TagDependency::invalidate($cache, 'test-tag');

        // Значение должно быть инвалидировано в обоих слоях
        self::assertFalse($cache->get('test_key'),
            'Value should be invalidated after recovery');
    }

    /**
     * Сценарий: Edge case - пустой массив тегов
     *
     * Проверяет корректную обработку пустого массива тегов.
     */
    public function testEmptyTagsArray(): void
    {
        $cache = $this->createTieredCache();

        // Значение с пустым массивом тегов (должно работать как без тегов)
        $cache->set('key1', 'value1', 3600, new TagDependency(['tags' => []]));

        self::assertEquals('value1', $cache->get('key1'),
            'Value with empty tags array should be accessible');

        // Инвалидация любого тега не должна затронуть это значение
        TagDependency::invalidate($cache, 'any-tag');

        self::assertEquals('value1', $cache->get('key1'),
            'Value with empty tags should not be affected by any invalidation');
    }

    /**
     * Сценарий: Инвалидация несуществующего тега
     *
     * Проверяет что инвалидация тега, который никогда не использовался,
     * не вызывает ошибок.
     */
    public function testInvalidateNonExistentTag(): void
    {
        $cache = $this->createTieredCache();

        // Инвалидируем тег, который не использовался
        TagDependency::invalidate($cache, 'non-existent-tag');

        // Должно работать без ошибок
        self::assertTrue(true, 'Invalidating non-existent tag should not cause errors');
    }

    /**
     * Сценарий: Проверка что dependency data сохраняется корректно
     *
     * Проверяет внутренний механизм работы TagDependency - сохранение
     * dependency data при записи значения.
     */
    public function testDependencyDataIsSavedCorrectly(): void
    {
        $cache = $this->createTieredCache();

        // Создаем TagDependency
        $dependency = new TagDependency(['tags' => ['tag-1', 'tag-2']]);

        // Сохраняем значение
        $cache->set('key1', 'value1', 3600, $dependency);

        // Dependency должна иметь сохраненные данные (timestamps)
        self::assertNotEmpty($dependency->data,
            'TagDependency should have saved timestamp data');

        // Проверяем что значение доступно
        self::assertEquals('value1', $cache->get('key1'));
    }

    /**
     * Сценарий: Множественная инвалидация одного тега
     *
     * Проверяет что повторная инвалидация тега не вызывает проблем.
     */
    public function testMultipleInvalidationsOfSameTag(): void
    {
        $cache = $this->createTieredCache();

        $cache->set('key1', 'value1', 3600, new TagDependency(['tags' => 'tag-a']));

        // Инвалидируем тег три раза подряд
        TagDependency::invalidate($cache, 'tag-a');
        TagDependency::invalidate($cache, 'tag-a');
        TagDependency::invalidate($cache, 'tag-a');

        // Значение должно быть недоступно
        self::assertFalse($cache->get('key1'));

        // Записываем новое значение с тем же тегом
        $cache->set('key1', 'value2', 3600, new TagDependency(['tags' => 'tag-a']));

        self::assertEquals('value2', $cache->get('key1'),
            'Should be able to set new value after multiple invalidations');
    }

    /**
     * Сценарий: Сложный кейс - пересечение тегов
     *
     * Проверяет сложный сценарий с пересекающимися тегами:
     * - value1: [tag-a, tag-b]
     * - value2: [tag-b, tag-c]
     * - value3: [tag-c, tag-d]
     */
    public function testComplexTagIntersection(): void
    {
        $cache = $this->createTieredCache();

        $cache->set('value1', 'data1', 3600, new TagDependency(['tags' => ['tag-a', 'tag-b']]));
        $cache->set('value2', 'data2', 3600, new TagDependency(['tags' => ['tag-b', 'tag-c']]));
        $cache->set('value3', 'data3', 3600, new TagDependency(['tags' => ['tag-c', 'tag-d']]));

        // Инвалидируем tag-b
        TagDependency::invalidate($cache, 'tag-b');

        // value1 и value2 должны быть инвалидированы, value3 - нет
        self::assertFalse($cache->get('value1'), 'value1 has tag-b, should be invalidated');
        self::assertFalse($cache->get('value2'), 'value2 has tag-b, should be invalidated');
        self::assertEquals('data3', $cache->get('value3'), 'value3 does not have tag-b, should remain');

        // Инвалидируем tag-c
        TagDependency::invalidate($cache, 'tag-c');

        // Теперь value3 тоже должен быть инвалидирован
        self::assertFalse($cache->get('value3'), 'value3 has tag-c, should be invalidated now');
    }

    /**
     * Вспомогательный метод создания TieredCache с дефолтной конфигурацией
     */
    private function createTieredCache(): TieredCache
    {
        return new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
                ['cache' => new ArrayCache()],
            ],
            'writeStrategy' => TieredCache::WRITE_THROUGH,
            'recoveryStrategy' => TieredCache::RECOVERY_POPULATE,
        ]);
    }
}
