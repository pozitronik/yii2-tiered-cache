<?php
declare(strict_types=1);

namespace Beeline\TieredCache\Cache;

use ReflectionClass;
use ReflectionProperty;
use yii\caching\Dependency;

/**
 * Метаданные dependency для оптимизированного хранения в кеше
 *
 * Вместо хранения полного объекта Dependency (который содержит evaluated data),
 * сохраняем только класс и параметры конфигурации. При восстановлении из кеша
 * dependency пересоздается и пересобирается.
 *
 * Это уменьшает размер кешируемых объектов, особенно при WRITE_THROUGH стратегии,
 * где один и тот же dependency дублируется во всех слоях.
 *
 * @example
 * ```php
 * // Создание из dependency
 * $dependency = new TagDependency(['tags' => ['user-123', 'profile']]);
 * $dependency->evaluateDependency($cache); // Сохраняет timestamps в $data
 * $meta = DependencyMetadata::fromDependency($dependency);
 *
 * // Восстановление
 * $restoredDependency = $meta->recreate();
 * // $restoredDependency уже содержит сохраненные timestamps
 * ```
 */
final readonly class DependencyMetadata
{
    /**
     * @param string $className Полное имя класса dependency (например, 'yii\caching\TagDependency')
     * @param array $config Параметры конфигурации для создания dependency (например, ['tags' => ['user-123']])
     * @param mixed|null $evaluatedData Evaluated данные dependency (например, timestamps для TagDependency)
     */
    public function __construct(
        public string $className,
        public array $config,
        public mixed $evaluatedData = null,
    ) {
    }

    /**
     * Создать metadata из существующего dependency объекта
     *
     * Извлекает класс, публичные свойства и evaluated data из dependency.
     *
     * @param Dependency $dependency Dependency объект (должен быть уже evaluated)
     *
     * @return self
     */
    public static function fromDependency(Dependency $dependency): self
    {
        $className = get_class($dependency);

        // Извлекаем публичные свойства dependency как конфигурацию
        $config = [];
        $reflection = new ReflectionClass($dependency);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();

            // Пропускаем свойство 'data' - оно хранится отдельно как evaluatedData
            if ('data' === $propertyName) {
                continue;
            }

            // Пропускаем статические свойства
            if ($property->isStatic()) {
                continue;
            }

            $config[$propertyName] = $property->getValue($dependency);
        }

        // Сохраняем evaluated data отдельно
        $evaluatedData = $dependency->data ?? null;

        return new self($className, $config, $evaluatedData);
    }

    /**
     * Воссоздать dependency объект из metadata
     *
     * Создает новый экземпляр dependency с сохраненными параметрами и evaluated data.
     *
     * ВАЖНО: Этот метод НЕ вызывает evaluateDependency(), а восстанавливает
     * ранее сохраненные evaluated данные. Это критично для корректной работы
     * механизма инвалидации:
     * - При записи в кеш: dependency evaluated → metadata сохраняет $dependency->data
     * - При чтении из кеша: metadata восстанавливает dependency с тем же $data
     * - Cache::get() сравнивает восстановленный $data с текущим состоянием
     *
     * @return Dependency
     */
    public function recreate(): Dependency
    {
        // Создаем новый экземпляр dependency с сохраненной конфигурацией
        /** @var Dependency $dependency */
        $dependency = new $this->className($this->config);

        // Восстанавливаем evaluated data без повторного evaluation
        // Это критично: мы должны восстановить СТАРЫЕ данные, чтобы Cache::get()
        // мог сравнить их с НОВЫМИ через isChanged()
        if (null !== $this->evaluatedData) {
            $dependency->data = $this->evaluatedData;
        }

        return $dependency;
    }
}
