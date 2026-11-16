import { Button } from '@/components/ui/button';
import { Moon, Sun } from 'lucide-react';
import { useState } from 'react';

/**
 * Console theme: dark by default (night shifts), light on demand. Remembered per device in a cookie
 * so the server renders the right theme on the next load.
 */
export function ThemeToggle() {
    const [dark, setDark] = useState(() => typeof document === 'undefined' || document.documentElement.classList.contains('dark'));

    function toggle() {
        const next = !dark;
        document.documentElement.classList.toggle('dark', next);
        document.cookie = `appearance=${next ? 'dark' : 'light'}; path=/; max-age=31536000; samesite=lax`;
        setDark(next);
    }

    return (
        <Button
            variant="ghost"
            size="icon"
            onClick={toggle}
            aria-label={dark ? 'Pasar a modo claro' : 'Pasar a modo oscuro'}
            title={dark ? 'Modo claro' : 'Modo oscuro'}
        >
            {dark ? <Sun className="size-5" /> : <Moon className="size-5" />}
        </Button>
    );
}
