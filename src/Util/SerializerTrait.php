<?php

namespace AA\Util;

/**
 */
trait SerializerTrait
{
    /**
     * Zpracuje pole dat z metody __unserialize, odstraní prefixy
     * a bezpečně přiřadí hodnoty, pouze pokud vlastnost existuje.
     * @param array $data Pole vlastností a hodnot.
     */
    protected function assignUnserializedData(array $data): void
    {
        foreach ($data as $prefixed_name => $value) {
            $clean_name = $this->getSerializedProperty($prefixed_name);
            if (property_exists($this, $clean_name)) {
                $this->$clean_name = $value;
            }
        }
    }

    /** helper method to read $data["current_id"] from PHP naturally serialized object by __unserialize(), where private/protected properties are prefixed with \0*\0 or \0ClassName\0
     *  Call as:
     *   $this->current_id = getSerializedValue($data, 'current_id', $this->start_id);
     */
    protected function getSerializedProperty(string $name): string
    {
        return str_contains($name, "\0") ? substr($name, strrpos($name, "\0") + 1) : $name;
        // return $data[$property] ?? $default;
        // return $data[$name] ?? $data["\0*\0{$name}"] ?? $data["\0" . static::class . "\0{$name}"] ?? $default;
    }

}
