@php
    $statePath = $getStatePath();
    $state = $getState();
    $existingUrl = null;
    if ($state && ! str_starts_with($state, 'data:image')) {
        $existingUrl = \Illuminate\Support\Facades\Storage::disk($getDisk())->url($state);
    }
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.entangle('{{ $statePath }}'),
            drawing: false,
            ctx: null,
            hasDrawing: false,
            init() {
                this.ctx = this.$refs.canvas.getContext('2d');
                this.ctx.strokeStyle = '#111827';
                this.ctx.lineWidth = 2.5;
                this.ctx.lineCap = 'round';
                const existing = @js($existingUrl);
                const dataUrl = (this.state && this.state.startsWith('data:image')) ? this.state : existing;
                if (dataUrl) {
                    const img = new Image();
                    img.onload = () => this.ctx.drawImage(img, 0, 0, this.$refs.canvas.width, this.$refs.canvas.height);
                    img.src = dataUrl;
                    this.hasDrawing = true;
                }
            },
            pos(e) {
                const rect = this.$refs.canvas.getBoundingClientRect();
                const p = e.touches && e.touches.length ? e.touches[0] : e;
                return { x: p.clientX - rect.left, y: p.clientY - rect.top };
            },
            start(e) {
                this.drawing = true;
                this.hasDrawing = true;
                const p = this.pos(e);
                this.ctx.beginPath();
                this.ctx.moveTo(p.x, p.y);
            },
            move(e) {
                if (! this.drawing) return;
                e.preventDefault();
                const p = this.pos(e);
                this.ctx.lineTo(p.x, p.y);
                this.ctx.stroke();
            },
            end() {
                if (! this.drawing) return;
                this.drawing = false;
                this.state = this.$refs.canvas.toDataURL('image/png');
            },
            clear() {
                this.ctx.clearRect(0, 0, this.$refs.canvas.width, this.$refs.canvas.height);
                this.state = null;
                this.hasDrawing = false;
            },
        }"
        class="fi-signature-pad"
    >
        <canvas
            x-ref="canvas"
            width="500"
            height="180"
            style="border:1px solid rgb(209 213 219); border-radius:0.5rem; touch-action:none; background:#fff; width:100%; max-width:500px; cursor:crosshair;"
            x-on:mousedown="start($event)"
            x-on:mousemove="move($event)"
            x-on:mouseup="end()"
            x-on:mouseleave="end()"
            x-on:touchstart="start($event)"
            x-on:touchmove="move($event)"
            x-on:touchend="end()"
        ></canvas>

        <button
            type="button"
            x-on:click="clear()"
            x-show="hasDrawing"
            class="fi-btn fi-btn-size-sm mt-2 text-sm text-danger-600 underline"
        >
            Cancella firma
        </button>
    </div>
</x-dynamic-component>
