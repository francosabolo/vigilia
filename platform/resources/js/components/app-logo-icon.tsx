import { SVGAttributes } from 'react';

/**
 * Vigilia mark: a camera lens with a watch sweep.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" fill="none">
            <circle cx="16" cy="16" r="13" stroke="currentColor" strokeWidth="2.5" />
            <path d="M16 16 L16 3 A13 13 0 0 1 28.4 12.2 Z" fill="currentColor" opacity="0.35" />
            <circle cx="16" cy="16" r="4.5" fill="currentColor" />
        </svg>
    );
}
