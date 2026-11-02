import { useState } from 'react';
import { Check } from 'lucide-react';

/** The radius choices staff set, plus any whole number up to the site's limit. */
export default function RadiusPicker({ legend, value, options, max, onChange }: { legend: string; value: number; options: number[]; max: number; onChange: (km: number) => void }) {
  const [custom, setCustom] = useState(!options.includes(value));
  const [typed, setTyped] = useState(String(value));

  const apply = () => {
    const km = Math.round(Number(typed));
    if (km >= 1 && km <= max) onChange(km);
  };

  return (
    <fieldset>
      <legend className="mb-2 font-semibold">{legend}</legend>
      <div className="flex flex-wrap gap-2">
        {options.map((km) => {
          const active = !custom && value === km;
          return (
            <button
              key={km}
              type="button"
              aria-pressed={active}
              onClick={() => {
                setCustom(false);
                onChange(km);
              }}
              className={`chip ${active ? 'chip-on' : ''}`}
            >
              {active && <Check className="size-4" aria-hidden="true" />}
              {km} km
            </button>
          );
        })}
        <button type="button" aria-pressed={custom} onClick={() => setCustom(true)} className={`chip ${custom ? 'chip-on' : ''}`}>Custom</button>
      </div>
      {custom && (
        <form
          className="mt-2 flex items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            apply();
          }}
        >
          <label className="flex-1">
            <span className="sr-only">Distance in kilometres, up to {max}</span>
            <input type="number" inputMode="numeric" min={1} max={max} step={1} value={typed} onChange={(e) => setTyped(e.target.value)} className="input" />
          </label>
          <span className="text-muted">km</span>
          <button className="btn btn-quiet">Apply</button>
        </form>
      )}
    </fieldset>
  );
}
