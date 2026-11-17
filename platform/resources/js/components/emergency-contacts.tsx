import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router, useForm } from '@inertiajs/react';
import { MessageCircle, Phone, X } from 'lucide-react';

export interface EmergencyContact {
    id: number;
    name: string;
    relation: string | null;
    phone: string;
}

/** Only digits, with the Argentine country code, as WhatsApp expects in wa.me links. */
export function whatsappNumber(phone: string): string {
    const digits = phone.replace(/\D/g, '');
    return digits.startsWith('54') ? digits : `54${digits}`;
}

/**
 * F7 — who to call when a resident is away or does not answer. Editable by the resident in the app and by the
 * property's staff; read-only with call and WhatsApp for the guard.
 */
export function EmergencyContacts({
    contacts,
    addAction,
    removeAction,
    compact = false,
}: {
    contacts: EmergencyContact[];
    addAction?: string;
    removeAction?: (contact: EmergencyContact) => string;
    compact?: boolean;
}) {
    const form = useForm({ name: '', relation: '', phone: '' });

    return (
        <div className="flex flex-col gap-2 text-sm">
            {contacts.length === 0 && <p className="text-muted-foreground">Sin contactos de emergencia.</p>}
            {contacts.map((contact) => (
                <div key={contact.id} className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="min-w-0 flex-1">
                        <span className="font-medium">{contact.name}</span>
                        {contact.relation && <span className="text-muted-foreground"> ({contact.relation})</span>}
                        {!compact && <span className="text-muted-foreground block">{contact.phone}</span>}
                    </span>
                    <a
                        href={`tel:${contact.phone}`}
                        className="inline-flex items-center gap-1 font-medium underline"
                        aria-label={`Llamar a ${contact.name}`}
                    >
                        <Phone className="size-3.5" /> Llamar
                    </a>
                    <a
                        href={`https://wa.me/${whatsappNumber(contact.phone)}`}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 font-medium underline"
                        aria-label={`WhatsApp a ${contact.name}`}
                    >
                        <MessageCircle className="size-3.5" /> WhatsApp
                    </a>
                    {removeAction && (
                        <button
                            type="button"
                            aria-label={`Quitar a ${contact.name}`}
                            className="text-muted-foreground hover:text-alarm"
                            onClick={() => router.delete(removeAction(contact), { preserveScroll: true })}
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
            ))}
            {addAction && contacts.length < 5 && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(addAction, { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                    className="mt-1 grid gap-2 sm:grid-cols-[1fr_8rem_10rem_auto]"
                >
                    <Input
                        aria-label="Nombre del contacto"
                        placeholder="Nombre"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <Input
                        aria-label="Relación"
                        placeholder="Ej: hija"
                        value={form.data.relation}
                        onChange={(e) => form.setData('relation', e.target.value)}
                    />
                    <Input
                        aria-label="Celular del contacto"
                        placeholder="+5491155550000"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                    <Button type="submit" variant="outline" disabled={form.processing || !form.data.name.trim() || !form.data.phone.trim()}>
                        Agregar
                    </Button>
                    <InputError message={form.errors.name ?? form.errors.phone} className="sm:col-span-4" />
                </form>
            )}
        </div>
    );
}
