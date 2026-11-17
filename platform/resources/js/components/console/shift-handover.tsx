import { SectionTitle } from '@/components/icon-circle';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { timeAgo } from '@/lib/seguria';
import { useForm } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { useState } from 'react';

export interface Handover {
    id: number;
    site: string | null;
    by: string | null;
    from: string;
    to: string;
    summary: string;
    notes: string | null;
    open_incidents: number;
    at: string;
}

/**
 * C5 — the last shift handover, and the button to hand the shift over (when one property is in view).
 */
export function ShiftHandoverCard({ last, siteId }: { last: Handover | null; siteId: number | null }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ notes: '' });

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <SectionTitle icon={ClipboardList}>Entrega de turno</SectionTitle>
                {siteId !== null && (
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button variant="outline" size="sm">
                                Entregar mi turno
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(`/sites/${siteId}/handover`, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            form.reset();
                                            setOpen(false);
                                        },
                                    });
                                }}
                                className="flex flex-col gap-4"
                            >
                                <DialogHeader>
                                    <DialogTitle>Entregar el turno</DialogTitle>
                                    <DialogDescription>
                                        La IA resume lo que pasó desde la última entrega y lo que sigue abierto. Sumá lo que la guardia que entra
                                        tiene que saber.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="handover-notes">Notas para el turno siguiente (opcional)</Label>
                                    <textarea
                                        id="handover-notes"
                                        rows={4}
                                        placeholder="Ej: el portón de servicio quedó trabado, avisé al encargado"
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    />
                                    <InputError message={form.errors.notes} />
                                </div>
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="ghost">
                                            Cancelar
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing ? 'Preparando el resumen…' : 'Entregar turno'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
            </div>
            {last ? (
                <div className="bg-card rounded-lg border p-4 text-sm">
                    <p className="text-muted-foreground">
                        {last.by ?? 'Alguien del equipo'}
                        {last.site ? `, ${last.site}` : ''}, {timeAgo(last.at)}
                    </p>
                    <p className="mt-1 text-[15px]">{last.summary}</p>
                    {last.notes && <p className="mt-2 border-l-2 pl-3">«{last.notes}»</p>}
                </div>
            ) : (
                <p className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">
                    Todavía no hay entregas de turno.{siteId === null ? ' Elegí una propiedad arriba para entregar el tuyo.' : ''}
                </p>
            )}
        </section>
    );
}
