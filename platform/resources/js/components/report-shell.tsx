import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * Printable document frame (monthly report, incident report). Light on screen and on paper; the controls hide when printing.
 */
export function ReportShell({
    title,
    organization,
    backHref,
    backLabel,
    controls,
    children,
}: {
    title: string;
    organization: string;
    backHref: string;
    backLabel: string;
    controls?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="min-h-screen bg-neutral-100 text-neutral-900 print:bg-white">
            <Head title={title} />
            <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-4 print:hidden">
                <Link href={backHref} className="flex items-center gap-1.5 text-sm text-neutral-600 hover:text-neutral-900">
                    <ArrowLeft className="size-4" /> {backLabel}
                </Link>
                <div className="flex items-center gap-2">
                    {controls}
                    <Button onClick={() => window.print()}>
                        <Printer className="mr-1 size-4" /> Guardar como PDF
                    </Button>
                </div>
            </div>
            <article className="mx-auto mb-10 max-w-3xl bg-white px-6 py-10 shadow-sm sm:px-12 print:m-0 print:max-w-none print:p-0 print:shadow-none">
                <header className="mb-8 flex items-center justify-between gap-4 border-b border-neutral-200 pb-4">
                    <div className="flex items-center gap-2 text-sm font-semibold">
                        <AppLogoIcon className="size-5 fill-current text-red-600" />
                        {organization}
                    </div>
                    <p className="text-sm text-neutral-500">Generado con Vigilia</p>
                </header>
                {children}
            </article>
        </div>
    );
}
