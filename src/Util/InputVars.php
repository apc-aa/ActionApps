<?php


namespace AA\Util;


use chillerlan\QRCode\Data\QRDataInterface;

class InputVars {
    public function pi(string $name): int    { return (int)$_POST[$name];    }
    public function pb(string $name): bool   { return (bool)$_POST[$name];   }
    public function ps(string $name): string { return (string)$_POST[$name]; }
    public function pl(string $name): string { return (string)$_POST[$name]; } // long id
    public function pa(string $name): array  { return (array)$_POST[$name];  }

    public function gi(string $name): int     { return (int)$_GET[$name];    }
    public function gb(string $name): bool    { return (bool)$_GET[$name];   }
    public function gs(string $name): string  { return (string)$_GET[$name]; }
    public function gl(string $name): string  { return (string)$_GET[$name]; }  // long id
    public function ga(string $name): array   { return (array)$_GET[$name];  }
}