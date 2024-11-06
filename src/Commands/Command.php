<?php
/**
 * @author Aaron Francis <aarondfrancis@gmail.com>
 * @link https://aaronfrancis.com
 * @link https://twitter.com/aarondfrancis
 */

namespace AaronFrancis\Solo\Commands;

use AaronFrancis\Solo\Commands\Concerns\ManagesProcess;
use Chewie\Concerns\Ticks;
use Chewie\Contracts\Loopable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SplQueue;

class Command implements Loopable
{
    use ManagesProcess, Ticks;

    public bool $focused = false;

    public bool $paused = false;

    public int $scrollIndex = 0;

    public SplQueue $lines;

    public int $height = 0;

    public int $width = 0;

    public function __construct(
        public readonly string $name,
        public readonly string $command,
        public bool $autostart = true
    ) {
        $this->clear();
    }

    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    public function setDimensions($width, $height): static
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function lazy(): static
    {
        $this->autostart = false;

        return $this;
    }

    public function onTick(): void
    {
        $this->marshalRogueProcess();

        $this->onNthTick(
            $this->focused ? 1 : 10, [$this, 'gatherLatestOutput']
        );
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function isBlurred(): bool
    {
        return !$this->isFocused();
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */
    public function addLine($line)
    {
        $this->lines->enqueue($line);

        // Enforce a strict 2000 line limit, which
        // seems like more than enough.
        if ($this->lines->count() > 2000) {
            $this->lines->dequeue();
        }
    }

    public function focus(): void
    {
        $this->focused = true;

        $this->gatherLatestOutput();
    }

    public function blur(): void
    {
        $this->focused = false;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function follow(): void
    {
        $this->paused = false;
    }

    public function clear(): void
    {
        $this->lines = new SplQueue;
    }

    public function catchUpScroll(): void
    {
        if (!$this->paused) {
            $this->scrollDown($this->lines->count());
            // `scrollDown` pauses, so turn follow back on.
            $this->follow();
        }
    }

    public function scrollDown($amount = 1): void
    {
        $this->paused = true;
        $this->scrollIndex = max(0, min(
            $this->scrollIndex + $amount,
            $this->wrappedLines()->count() - $this->scrollPaneHeight()
        ));
    }

    public function scrollUp($amount = 1): void
    {
        $this->paused = true;
        $this->scrollIndex = max(
            $this->scrollIndex - $amount, 0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Log management
    |--------------------------------------------------------------------------
    */
    public function scrollPaneHeight(): int
    {
        // 5 = 1 tabs + 1 process + 1 top border + 1 bottom border + 1 hotkeys
        return $this->height - 5;
    }

    public function wrappedLines(): Collection
    {
        return collect($this->lines)
            ->flatMap(function ($line) {
                return Arr::wrap($this->wrapAndFormat($line));
            })
            ->pipe(fn(Collection $lines) => $this->modifyWrappedLines($lines))
            ->values();
    }

    protected function wrapAndFormat($line): string|array
    {
        return $this->wrapLine($line);
    }

    protected function wrapLine($line, $width = null): array
    {
        // 2 box borders + 2 spaces for padding.
        $defaultWidth = $this->width - 4;

        if (is_int($width)) {
            $width = $width < 0 ? $defaultWidth + $width : $width;
        }

        if (!$width) {
            $width = $defaultWidth;
        }

        return explode(PHP_EOL, wordwrap(
            string: $line,
            width: $width,
            cut_long_words: true
        ));
    }

    protected function modifyWrappedLines(Collection $lines): Collection
    {
        // Primarily here for any subclasses.
        return $lines;
    }

    protected function gatherLatestOutput(): void
    {
        if (!$latest = $this->process?->latestOutput()) {
            return;
        }

        // All logs have a phantom trailing newline
        $newLines = explode(PHP_EOL, Str::chopEnd($latest, "\n"));

        foreach ($newLines as $line) {
            $this->addLine($line);
        }
    }
}
