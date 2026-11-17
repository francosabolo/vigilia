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
import { formatDateTime, type IncidentDetail } from '@/lib/vigilia';
import { router } from '@inertiajs/react';
import { Copy, Megaphone, PhoneCall } from 'lucide-react';
import { useState } from 'react';

const QUICK_MESSAGES = [
    'Atención: está siendo filmado. La guardia ya fue avisada y está en camino.',
    'Esta es una propiedad privada. Retírese ahora.',
    'Seguridad: no se mueva, la guardia está llegando.',
];

/**
 * B7 — talk to the person through the camera's speaker. The message is read on site by the edge.
 */
export function SpeakerDialog({ incident }: { incident: IncidentDetail }) {
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState(QUICK_MESSAGES[0]);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Megaphone className="mr-1 size-4" /> Hablar por el altavoz
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Hablar por el altavoz de {incident.camera ?? 'la cámara'}</DialogTitle>
                    <DialogDescription>El equipo lo lee en voz alta en el lugar. Elegí un mensaje o escribí el tuyo.</DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-2">
                    {QUICK_MESSAGES.map((quick) => (
                        <label
                            key={quick}
                            className="has-[:checked]:border-foreground flex cursor-pointer items-start gap-2 rounded-lg border p-2 text-sm"
                        >
                            <input type="radio" checked={message === quick} onChange={() => setMessage(quick)} className="accent-foreground mt-0.5" />
                            {quick}
                        </label>
                    ))}
                    <Label htmlFor="speaker-message" className="mt-2">
                        Otro mensaje
                    </Label>
                    <textarea
                        id="speaker-message"
                        rows={2}
                        maxLength={200}
                        value={QUICK_MESSAGES.includes(message) ? '' : message}
                        onChange={(e) => setMessage(e.target.value)}
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancelar</Button>
                    </DialogClose>
                    <Button
                        disabled={!message.trim()}
                        onClick={() =>
                            router.post(`/incidents/${incident.id}/speaker`, { message }, { preserveScroll: true, onSuccess: () => setOpen(false) })
                        }
                    >
                        Reproducir en el lugar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * B8 — call 911 with the summary ready to read: what, where, when, how many, where they went.
 */
export function EmergencyCallDialog({ incident, address }: { incident: IncidentDetail; address: string | null }) {
    const [copied, setCopied] = useState(false);
    const route = incident.route.length >= 2 ? ` Se lo vio pasar por ${incident.route.map((stop) => stop.camera).join(', ')}.` : '';
    const summary = [
        `Llamo de la seguridad de ${incident.site.name}${address ? `, ${address}` : ''}.`,
        `${incident.title}, detectado el ${formatDateTime(incident.detected_at)}${incident.zone ? ` en la zona ${incident.zone}` : ''}.`,
        incident.description ?? '',
        route.trim(),
        incident.acknowledged_by ? `La guardia (${incident.acknowledged_by}) está en el lugar.` : 'La guardia fue avisada.',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="border-alarm/60 text-alarm hover:bg-alarm/10 hover:text-alarm">
                    <PhoneCall className="mr-1 size-4" /> Llamar al 911
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Llamar al 911</DialogTitle>
                    <DialogDescription>Leé este resumen cuando te atiendan. Tiene lo que suelen preguntar primero.</DialogDescription>
                </DialogHeader>
                <p className="bg-muted rounded-lg p-4 text-[15px] leading-relaxed">{summary}</p>
                <DialogFooter className="gap-2 sm:justify-between">
                    <Button variant="ghost" onClick={() => navigator.clipboard.writeText(summary).then(() => setCopied(true))}>
                        <Copy className="mr-1 size-4" /> {copied ? 'Copiado' : 'Copiar el resumen'}
                    </Button>
                    <Button asChild variant="destructive">
                        <a href="tel:911">
                            <PhoneCall className="mr-1 size-4" /> Llamar ahora
                        </a>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
