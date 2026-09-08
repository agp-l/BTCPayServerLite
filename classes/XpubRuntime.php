<?php

declare(strict_types=1);
namespace BtcPayLite;

/** Shared installer/provisioning check for the existing bitcoin-p8 dependency. No wallet or RPC. */
final class XpubRuntime
{
    public static function requirements(): array
    {
        $gmp=extension_loaded('gmp');
        $dependencies=class_exists(\BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory::class)
            && class_exists(\Mdanter\Ecc\EccFactory::class)
            && class_exists(\BitWasp\Buffertools\Buffer::class);
        return [
            ['name'=>'GMP pro XPUB','ok'=>$gmp,'required'=>true,
                'detail'=>$gmp ? 'Dostupné' : 'Zapněte rozšíření gmp v PHP webového serveru a restartujte jej. Terminál může používat jiné PHP.'],
            ['name'=>'Composer knihovny pro XPUB','ok'=>$dependencies,'required'=>true,
                'detail'=>$dependencies ? 'Dostupné' : 'V adresáři projektu spusťte composer install --no-dev --prefer-dist. Samotný git pull knihovny neinstaluje.'],
        ];
    }

    public static function assertAvailable(): void
    {
        foreach (self::requirements() as $requirement) {
            if (!$requirement['ok']) {
                throw new StoreCreationException($requirement['name']==='GMP pro XPUB' ? 'xpub_gmp_missing' : 'xpub_dependencies_missing',$requirement['detail']);
            }
        }
    }
}
