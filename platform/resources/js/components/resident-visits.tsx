import { AccessAuthorizationForm } from '@/components/access-authorization-form';
import { IconCircle } from '@/components/icon-circle';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { timeAgo } from '@/lib/vigilia';
import { router } from '@inertiajs/react';
import { DoorOpen } from 'lucide-react';
import { useState } from 'react';

export interface ResidentVisit {
    id: number;
    kind_label: string;
    name: string;
    code: string;
    valid_from: string;
    valid_until: string;
    time_from: string | null;
    time_to: string | null;
    valid_now: boolean;
}

export interface GateEntry {
    id: number;
    name: string;
    plate: string | null;
    status: 'allowed' | 'denied' | 'waiting';
    at: string;
}

/**
 * D1/D2 in the resident's app: who is at the gate now, invite someone with a code and a QR, and cancel it.
 */
export function ResidentVisits({ visits, atGate, siteName }: { visits: ResidentVisit[]; atGate: GateEntry[]; siteName: string | null }) {
    const [inviting, setInviting] = useState(false);
    const [qrFor, setQrFor] = useState<number | null>(null);
    const waiting = atGate.filter((entry) => entry.status === 'waiting');

    return (
        <Card id="visitas">
            <CardHeader>
                <CardTitle className="flex items-center gap-3 text-lg">
                    <IconCircle icon={DoorOpen} /> Visitas
                </CardTitle>
                <CardDescription>Invitá a alguien y mandale el código: la guardia lo deja pasar sin llamarte.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {waiting.map((entry) => (
                    <div key={entry.id} className="border-alarm bg-alarm/10 flex flex-col gap-3 rounded-xl border-2 p-4">
                        <p className="font-semibold">
                            {entry.name} está en la guardia{entry.plate ? ` (patente ${entry.plate})` : ''} y pregunta por vos.
                        </p>
                        <div className="grid grid-cols-2 gap-2">
                            <Button onClick={() => router.post(`/vecinos/accesos/${entry.id}/respuesta`, { allow: true }, { preserveScroll: true })}>
                                Dejarlo pasar
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => router.post(`/vecinos/accesos/${entry.id}/respuesta`, { allow: false }, { preserveScroll: true })}
                            >
                                No lo conozco
                            </Button>
                        </div>
                    </div>
                ))}

                {visits.length === 0 && !inviting && <p className="text-muted-foreground text-sm">No tenés visitas autorizadas.</p>}
                {visits.map((visit) => {
                    const text = `Hola, ${visit.name}. Para entrar a ${siteName ?? 'mi casa'} decile este código a la guardia: ${visit.code}`;
                    return (
                        <div key={visit.id} className="flex flex-col gap-2 border-t pt-3 text-sm first:border-t-0 first:pt-0">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium">
                                        {visit.name} <span className="text-muted-foreground font-normal">({visit.kind_label.toLowerCase()})</span>
                                    </p>
                                    <p className="text-muted-foreground">
                                        {visit.valid_from === visit.valid_until
                                            ? new Date(visit.valid_from + 'T12:00').toLocaleDateString('es-AR')
                                            : `hasta el ${new Date(visit.valid_until + 'T12:00').toLocaleDateString('es-AR')}`}
                                        {visit.time_from && `, desde las ${visit.time_from}`}
                                        {visit.time_to && ` hasta las ${visit.time_to}`}
                                    </p>
                                </div>
                                <span className="font-display text-2xl font-semibold tracking-widest tabular-nums">{visit.code}</span>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild size="sm" variant="secondary">
                                    <a href={`https://wa.me/?text=${encodeURIComponent(text)}`} target="_blank" rel="noreferrer">
                                        Mandar por WhatsApp
                                    </a>
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setQrFor(qrFor === visit.id ? null : visit.id)}>
                                    {qrFor === visit.id ? 'Ocultar QR' : 'Ver QR'}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => router.delete(`/vecinos/visitas/${visit.id}`, { preserveScroll: true })}
                                >
                                    Cancelar
                                </Button>
                            </div>
                            {qrFor === visit.id && (
                                <img
                                    src={`/vecinos/visitas/${visit.id}/qr`}
                                    alt={`QR de ${visit.name}`}
                                    className="size-48 self-center rounded bg-white p-2"
                                />
                            )}
                        </div>
                    );
                })}

                {inviting ? (
                    <div className="border-t pt-4">
                        <AccessAuthorizationForm action="/vecinos/visitas" onDone={() => setInviting(false)} />
                    </div>
                ) : (
                    <Button variant="outline" onClick={() => setInviting(true)}>
                        Invitar a alguien
                    </Button>
                )}

                {atGate.filter((entry) => entry.status !== 'waiting').length > 0 && (
                    <div className="text-muted-foreground border-t pt-3 text-sm">
                        <p className="text-foreground mb-1 font-medium">Últimas 24 horas</p>
                        {atGate
                            .filter((entry) => entry.status !== 'waiting')
                            .map((entry) => (
                                <p key={entry.id}>
                                    {entry.name}: {entry.status === 'allowed' ? 'entró' : 'no entró'} {timeAgo(entry.at)}
                                </p>
                            ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
