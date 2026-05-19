<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Regenerates the placeholder JPGs in fixtures/photos/. The output JPGs are
 * committed to git so fixture loading doesn't need GD at runtime — this
 * command only needs to be re-run when you want to change the set of
 * placeholders (different colours, sizes, labels).
 */
#[AsCommand(
    name: 'app:generate-fixture-photos',
    description: 'Regenerate the placeholder JPGs used by Storage(Type)PhotoFixtures.',
)]
final class GenerateFixturePhotosCommand extends Command
{
    /**
     * @var list<array{string, int, int, string, string}>
     */
    private const PHOTOS = [
        ['box-blue-landscape.jpg',        800,  600, '#3b82f6', 'Sklad — modrý'],
        ['box-orange-wide.jpg',          1024,  576, '#f97316', 'Sklad — oranžový (16:9)'],
        ['box-gray-portrait.jpg',         480,  720, '#6b7280', 'Sklad — šedý (na výšku)'],
        ['box-green-square.jpg',          640,  640, '#10b981', 'Sklad — zelený (1:1)'],
        ['container-red.jpg',            1200,  800, '#ef4444', 'Skladovací jednotka — červená'],
        ['container-teal.jpg',            900,  600, '#14b8a6', 'Skladovací jednotka — tyrkysová'],
        ['interior-purple.jpg',          1024,  768, '#8b5cf6', 'Interiér — fialový (4:3)'],
        ['interior-yellow-portrait.jpg',  600,  900, '#eab308', 'Interiér — žlutý (na výšku)'],
    ];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!\function_exists('imagecreatetruecolor')) {
            $io->error('GD extension is required.');

            return Command::FAILURE;
        }

        $outputDir = $this->projectDir.'/fixtures/photos';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            $io->error(sprintf('Cannot create %s', $outputDir));

            return Command::FAILURE;
        }

        foreach (self::PHOTOS as [$filename, $width, $height, $bgHex, $label]) {
            $path = $outputDir.'/'.$filename;
            $this->generateJpeg($path, $width, $height, $bgHex, $label);
            $io->writeln(sprintf('Wrote %s (%d×%d)', $filename, $width, $height));
        }

        $io->success(sprintf('Generated %d fixture photos.', \count(self::PHOTOS)));

        return Command::SUCCESS;
    }

    private function generateJpeg(string $path, int $width, int $height, string $bgHex, string $label): void
    {
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        \assert(false !== $img);

        [$r, $g, $b] = $this->parseHexColour($bgHex);
        $bg = imagecolorallocate($img, $r, $g, $b);
        \assert(false !== $bg);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $bg);

        // Subtle darker grid so the image isn't perfectly flat.
        $grid = imagecolorallocatealpha(
            $img,
            $this->clamp255($r - 40),
            $this->clamp255($g - 40),
            $this->clamp255($b - 40),
            80,
        );
        \assert(false !== $grid);
        $step = 80;
        for ($x = 0; $x < $width; $x += $step) {
            imageline($img, $x, 0, $x, $height - 1, $grid);
        }
        for ($y = 0; $y < $height; $y += $step) {
            imageline($img, 0, $y, $width - 1, $y, $grid);
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        \assert(false !== $white);
        $charW = imagefontwidth(5);
        $charH = imagefontheight(5);
        $textW = $charW * mb_strlen($label);
        $textX = (int) (($width - $textW) / 2);
        $textY = (int) (($height - $charH) / 2);
        imagestring($img, 5, $textX, $textY, $label, $white);

        imagejpeg($img, $path, 75);
    }

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private function parseHexColour(string $hex): array
    {
        $parts = sscanf($hex, '#%02x%02x%02x');
        if (!\is_array($parts)) {
            throw new \InvalidArgumentException(sprintf('Invalid hex colour: %s', $hex));
        }

        return [
            $this->clamp255((int) $parts[0]),
            $this->clamp255((int) $parts[1]),
            $this->clamp255((int) $parts[2]),
        ];
    }

    /**
     * @return int<0, 255>
     */
    private function clamp255(int $value): int
    {
        return max(0, min(255, $value));
    }
}
