<?php

declare(strict_types=1);

namespace cosmoverse\fasterchest;

use cosmoverse\fasterchest\barrel\FasterBarrel;
use cosmoverse\fasterchest\command\FasterchestCommand;
use Generator;
use LevelDB;
use pocketmine\block\Barrel;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\Chest;
use pocketmine\block\tile\Barrel as VanillaBarrelTile;
use pocketmine\block\tile\Chest as VanillaChestTile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\Listener;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\event\world\ChunkUnloadEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\event\world\WorldUnloadEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use ReflectionProperty;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use Symfony\Component\Filesystem\Path;
use function count;
use const LEVELDB_ZLIB_RAW_COMPRESSION;

final class Loader extends PluginBase implements Listener {
    use SingletonTrait;

    public const CHEST_TILE_ID = "fasterchest:chest";
    public const BARREL_TILE_ID = "fasterchest:barrel";

    private Chest $internal_chest_block;
    private Barrel $internal_barrel_block;

    /** @var array<string, FasterChestChunkListener> */
    private array $chunk_listeners = [];

    /**
     * @throws \ReflectionException
     */
    protected function onLoad() : void{
        self::setInstance($this);

        TileFactory::getInstance()->register(FasterChest::class, [self::CHEST_TILE_ID]);
        TileFactory::getInstance()->register(FasterBarrel::class, [self::BARREL_TILE_ID]);

        if($this->getServer()->getConfigGroup()->getPropertyInt(YmlServerProperties::DEBUG_LEVEL, 1) > 1){
            FasterChest::$logger = $this->getLogger();
            FasterBarrel::$logger = $this->getLogger();
        }

        if(!isset(FasterChest::$serializer)){
            FasterChest::$serializer = DefaultFasterChestSerializer::instance();
        }
        if(!isset(FasterBarrel::$serializer)){
            FasterBarrel::$serializer = DefaultFasterChestSerializer::instance();
        }

        // we will be using an 'internal block' to set FasterChest tile in worlds (we will NOT use World::addTile, ::removeTile)
        // this 'internal block' is the vanilla chest block but backed with a FasterChest tile class.
        $internal_chest_block = VanillaBlocks::CHEST();
        $_idInfo = new ReflectionProperty($internal_chest_block, "idInfo");
        $_idInfo->setValue($internal_chest_block, new BlockIdentifier($internal_chest_block->getIdInfo()->getBlockTypeId(), FasterChest::class));
        $this->internal_chest_block = $internal_chest_block;

        $internal_barrel_block = VanillaBlocks::BARREL();
        $_idInfo = new ReflectionProperty($internal_barrel_block, "idInfo");
        $_idInfo->setValue($internal_barrel_block, new BlockIdentifier($internal_barrel_block->getIdInfo()->getBlockTypeId(), FasterBarrel::class));
        $this->internal_barrel_block = $internal_barrel_block;
    }

    protected function onEnable() : void{
        FasterChest::$database = new LevelDB(Path::join($this->getDataFolder(), "chest.db"), [
            "compression" => LEVELDB_ZLIB_RAW_COMPRESSION,
            "block_size" => 64 * 1024
        ]);
        FasterBarrel::$database = FasterChest::$database;

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        foreach($this->getServer()->getWorldManager()->getWorlds() as $world){
            $this->applyListenerToWorld($world);
        }

        Server::getInstance()->getCommandMap()->register("fasterchest", new FasterchestCommand());
    }

    protected function onDisable() : void{
    }

    private function applyListenerToWorld(World $world) : void{
        $listener = ($this->chunk_listeners[$world->getId()] ??= new FasterChestChunkListener($this, $world));
        foreach($world->getLoadedChunks() as $hash => $_){
            World::getXZ($hash, $x, $z);
            $world->registerChunkListener($listener, $x, $z);
        }
    }

    /**
     * @param WorldLoadEvent $event
     * @priority LOWEST
     */
    public function onWorldLoad(WorldLoadEvent $event) : void{
        $this->applyListenerToWorld($event->getWorld());
    }

    /**
     * @param WorldUnloadEvent $event
     * @priority MONITOR
     */
    public function onWorldUnload(WorldUnloadEvent $event) : void{
        $world = $event->getWorld();
        $listener = $this->chunk_listeners[$world->getId()];
        unset($this->chunk_listeners[$world->getId()]);
        $world->unregisterChunkListenerFromAll($listener);
    }

    /**
     * @param ChunkLoadEvent $event
     * @priority LOWEST
     */
    public function onChunkLoad(ChunkLoadEvent $event) : void{
        $world = $event->getWorld();
        if(isset($this->chunk_listeners[$world->getId()])){
            $world->registerChunkListener($this->chunk_listeners[$world->getId()], $event->getChunkX(), $event->getChunkZ());
        }
    }

    /**
     * @param ChunkUnloadEvent $event
     * @priority MONITOR
     */
    public function onChunkUnload(ChunkUnloadEvent $event) : void{
        $world = $event->getWorld();
        if(isset($this->chunk_listeners[$world->getId()])){
            $world->unregisterChunkListener($this->chunk_listeners[$world->getId()], $event->getChunkX(), $event->getChunkZ());
        }
    }

    /**
     * @return Generator<mixed, Await::RESOLVE, void, void>
     */
    private function sleep() : Generator{
        return Await::promise(fn($resolve) => $this->getScheduler()->scheduleDelayedTask(new ClosureTask($resolve), 1));
    }

    public function convertChestTile(VanillaChestTile $tile) : ?int{
        $position = $tile->getPosition();
        $world = $position->world;
        $block = $world->getBlockAt($position->x, $position->y, $position->z);
        if(!($block instanceof Chest)){
            return null;
        }

        $tile->unpair();
        $contents = $tile->getRealInventory()->getContents();
        $name = $tile->hasName() ? $tile->getName() : null;
        $new_block = (clone $this->internal_chest_block)->setFacing($block->getFacing());

        $world->setBlockAt($position->x, $position->y, $position->z, $new_block, false);
        $new_tile = $world->getTileAt($position->x, $position->y, $position->z);
        $new_tile instanceof FasterChest || throw new RuntimeException("Expected internal block to set a faster chest tile, got " . ($new_tile !== null ? $new_tile::class : "null"));
        $new_tile->getRealInventory()->setContents($contents);
        if($name !== null){
            $new_tile->setName($name);
        }
        $world->getBlockAt($position->x, $position->y, $position->z)->onPostPlace();
        return count($contents);
    }

    public function convertBarrelTile(VanillaBarrelTile $tile) : ?int{
        $position = $tile->getPosition();
        $world = $position->world;
        $block = $world->getBlockAt($position->x, $position->y, $position->z);
        if(!($block instanceof Barrel)){
            return null;
        }

        $contents = $tile->getInventory()->getContents();
        $name = $tile->hasName() ? $tile->getName() : null;
        $new_block = (clone $this->internal_barrel_block)->setFacing($block->getFacing());

        $world->setBlockAt($position->x, $position->y, $position->z, $new_block, false);
        $new_tile = $world->getTileAt($position->x, $position->y, $position->z);
        $new_tile instanceof FasterBarrel || throw new RuntimeException("Expected internal block to set a faster barrel tile, got " . ($new_tile !== null ? $new_tile::class : "null"));
        $new_tile->getInventory()->setContents($contents);
        if($name !== null){
            $new_tile->setName($name);
        }
        return count($contents);
    }

    public function convertTile($tile) : ?int{
        if($tile instanceof VanillaChestTile){
            return $this->convertChestTile($tile);
        }
        if($tile instanceof VanillaBarrelTile){
            return $this->convertBarrelTile($tile);
        }
        return null;
    }

    public function revertChestTile(FasterChest $tile) : ?int{
        $position = $tile->getPosition();
        $world = $position->world;

        $block = $world->getBlockAt($position->x, $position->y, $position->z);
        if(!($block instanceof Chest)){
            return null;
        }

        $tile->unpair();
        $identifier = FasterChest::dbIdFromPosition($position);
        FasterChest::$database->delete($identifier);
        $contents = $tile->getRealInventory()->getContents();
        $name = $tile->hasName() ? $tile->getName() : null;
        $listener = $this->chunk_listeners[$world->getId()];
        $new_block = VanillaBlocks::CHEST()->setFacing($block->getFacing());

        $world->setBlockAt($position->x, $position->y, $position->z, VanillaBlocks::AIR(), false);
        $listener->excluding($position->x, $position->y, $position->z, static fn() => $world->setBlockAt($position->x, $position->y, $position->z, $new_block, false));
        $new_tile = $world->getTileAt($position->x, $position->y, $position->z);
        $new_tile instanceof VanillaChestTile || throw new RuntimeException("Chest block did not set a chest tile, got " . ($new_tile !== null ? $new_tile::class : "null"));
        $new_tile->getRealInventory()->setContents($contents);
        if($name !== null){
            $new_tile->setName($name);
        }
        $world->getBlockAt($position->x, $position->y, $position->z)->onPostPlace();
        return count($contents);
    }

    public function revertBarrelTile(FasterBarrel $tile) : ?int{
        $position = $tile->getPosition();
        $world = $position->world;

        $block = $world->getBlockAt($position->x, $position->y, $position->z);
        if(!($block instanceof Barrel)){
            return null;
        }

        $identifier = FasterBarrel::dbIdFromPosition($position);
        FasterBarrel::$database->delete($identifier);
        $contents = $tile->getInventory()->getContents();
        $name = $tile->hasName() ? $tile->getName() : null;
        $listener = $this->chunk_listeners[$world->getId()];
        $new_block = VanillaBlocks::BARREL()->setFacing($block->getFacing());

        $world->setBlockAt($position->x, $position->y, $position->z, VanillaBlocks::AIR(), false);
        $listener->excluding($position->x, $position->y, $position->z, static fn() => $world->setBlockAt($position->x, $position->y, $position->z, $new_block, false));
        $new_tile = $world->getTileAt($position->x, $position->y, $position->z);
        $new_tile instanceof VanillaBarrelTile || throw new RuntimeException("Barrel block did not set a barrel tile, got " . ($new_tile !== null ? $new_tile::class : "null"));
        $new_tile->getInventory()->setContents($contents);
        if($name !== null){
            $new_tile->setName($name);
        }
        return count($contents);
    }

    public function revertTile($tile) : ?int{
        if($tile instanceof FasterChest){
            return $this->revertChestTile($tile);
        }
        if($tile instanceof FasterBarrel){
            return $this->revertBarrelTile($tile);
        }
        return null;
    }

    /**
     * @param World $world
     * @param int $max_ops_per_tick maximum chunk reads per tick - when this value is exceeded, this task sleeps (waits for next server tick)
     * @return Generator<mixed, Await::RESOLVE, void, int>
     */
    public function convertWorld(World $world, int $max_ops_per_tick = 128) : Generator{
        $total_conversions = 0;
        $read = 0;
        foreach($world->getProvider()->getAllChunks(false, $this->getLogger()) as $coords => $data){
            if(++$read % $max_ops_per_tick === 0){
                yield from $this->sleep();
            }

            [$x, $z] = $coords;

            $was_loaded = $world->isChunkLoaded($x, $z);
            if(!$was_loaded && count($data->getData()->getTileNBT()) === 0){
                continue;
            }

            $chunk = $world->loadChunk($x, $z);
            if($chunk === null){
                continue;
            }

            $converted = 0;
            foreach($chunk->getTiles() as $tile){
                $item_count = null;
                $tile_type = null;

                if($tile instanceof VanillaChestTile){
                    $item_count = $this->convertChestTile($tile);
                    $tile_type = "chest";
                } elseif($tile instanceof VanillaBarrelTile){
                    $item_count = $this->convertBarrelTile($tile);
                    $tile_type = "barrel";
                }

                if($item_count === null){
                    continue;
                }

                $this->getLogger()->info("Converted {$tile_type} at {$tile->getPosition()} ({$item_count} item(s))");
                $converted++;
            }

            if(!$was_loaded){
                $world->unloadChunk($x, $z);
            }
            $total_conversions += $converted;
        }
        return $total_conversions;
    }

    /**
     * @param World $world
     * @param int $max_ops_per_tick maximum chunk reads per tick - when this value is exceeded, this task sleeps (waits for next server tick)
     * @return Generator<mixed, Await::RESOLVE, void, int>
     */
    public function revertWorld(World $world, int $max_ops_per_tick = 128) : Generator{
        $total_reversions = 0;
        $read = 0;
        foreach($world->getProvider()->getAllChunks(false, $this->getLogger()) as $coords => $data){
            if(++$read % $max_ops_per_tick === 0){
                yield from $this->sleep();
            }

            [$x, $z] = $coords;
            $was_loaded = $world->isChunkLoaded($x, $z);
            if(!$was_loaded && count($data->getData()->getTileNBT()) === 0){
                continue;
            }

            $chunk = $world->loadChunk($x, $z);
            if($chunk === null){
                continue;
            }

            $reverted = 0;
            foreach($chunk->getTiles() as $tile){
                $item_count = null;
                $tile_type = null;

                if($tile instanceof FasterChest){
                    $item_count = $this->revertChestTile($tile);
                    $tile_type = "chest";
                } elseif($tile instanceof FasterBarrel){
                    $item_count = $this->revertBarrelTile($tile);
                    $tile_type = "barrel";
                }

                if($item_count === null){
                    continue;
                }

                $this->getLogger()->info("Reverted {$tile_type} at {$tile->getPosition()} ({$item_count} item(s))");
                $reverted++;
            }

            if(!$was_loaded){
                $world->unloadChunk($x, $z);
            }
            $total_reversions += $reverted;
        }
        return $total_reversions;
    }
}