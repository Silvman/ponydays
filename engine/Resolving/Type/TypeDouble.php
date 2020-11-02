<?php

namespace Engine\Resolving\Type;

class TypeDouble extends Type
{
    public function isScalar(): bool
    {
        return true;
    }

    protected function canAcceptType(Type $other): bool
    {
        return $other->isScalar() || $other instanceof TypeString;
    }
}