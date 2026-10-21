<!-- torsade/resources/js/components/network/PlaceDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { trans } from '@/lib/i18n';
import {
    store as storeBuilding,
    update as updateBuilding,
} from '@/routes/buildings';
import { store as storeFloor, update as updateFloor } from '@/routes/floors';
import { store as storeRoom, update as updateRoom } from '@/routes/rooms';
import type { PlaceBuilding, PlaceFloor, PlaceRoom } from '@/types';

// What the window is asked to create or to modify: a building of a site, a
// floor of a building or a room of a floor. A new place names what holds
// it; an existing one is given as it is.
export type PlaceTarget =
    | { kind: 'building'; siteId: number; place: PlaceBuilding | null }
    | {
          kind: 'floor';
          building: PlaceBuilding;
          place: PlaceFloor | null;
      }
    | {
          kind: 'room';
          building: PlaceBuilding;
          floor: PlaceFloor;
          place: PlaceRoom | null;
      };

// A short form in a window: a building, a floor or a room is created or
// modified without leaving the page of the site.
const props = defineProps<{
    open: boolean;
    target: PlaceTarget | null;
    // Every place of the site: an existing floor can be moved to another of
    // its buildings, an existing room to another of its floors. A place
    // never leaves its site.
    places: PlaceBuilding[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    name: string;
    code: string;
    // A number once typed: the field is a numeric input.
    level: string | number;
    is_technical: boolean;
    description: string;
    // Where an existing floor or room is, or is moved to.
    building_id: number | null;
    floor_id: number | null;
}>({
    name: '',
    code: '',
    level: '0',
    is_technical: false,
    description: '',
    building_id: null,
    floor_id: null,
});

// The floors of the site a room can be on, each with its building.
const floorOptions = computed<{ id: number; path: string }[]>(() =>
    props.places.flatMap((building) =>
        building.floors.map((floor) => ({
            id: floor.id,
            path: `${building.name} › ${floor.name}`,
        })),
    ),
);

watch(
    () => props.open,
    (open) => {
        const target = props.target;

        if (!open || !target) {
            return;
        }

        form.clearErrors();
        form.name = target.place?.name ?? '';
        form.description = target.place?.description ?? '';
        form.code =
            target.kind === 'building' ? (target.place?.code ?? '') : '';
        form.level =
            target.kind === 'floor' ? String(target.place?.level ?? 0) : '0';
        form.is_technical =
            target.kind === 'room'
                ? (target.place?.is_technical ?? false)
                : false;
        form.building_id = target.kind === 'floor' ? target.building.id : null;
        form.floor_id = target.kind === 'room' ? target.floor.id : null;
    },
);

const title = computed<string>(() => {
    const target = props.target;

    if (!target) {
        return '';
    }

    switch (target.kind) {
        case 'building':
            return target.place
                ? trans('Edit the building')
                : trans('New building');
        case 'floor':
            return target.place ? trans('Edit the floor') : trans('New floor');
        default:
            return target.place ? trans('Edit the room') : trans('New room');
    }
});

// Where the place is: what holds it is said, since the window hides the
// tree it was opened from.
const description = computed<string>(() => {
    const target = props.target;

    if (!target) {
        return '';
    }

    switch (target.kind) {
        case 'building':
            return trans(
                'A building of the site. Its floors and their rooms say where the devices are.',
            );
        case 'floor':
            return trans('A floor of the building :building.', {
                building: target.building.name,
            });
        default:
            return trans('A room of :place.', {
                place: `${target.building.name} › ${target.floor.name}`,
            });
    }
});

const submit = (): void => {
    const target = props.target;

    if (!target) {
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    // Only the fields of the kind of place are sent.
    form.transform((data) => {
        switch (target.kind) {
            case 'building':
                return {
                    name: data.name,
                    code: data.code,
                    description: data.description,
                };
            case 'floor':
                return {
                    level: data.level,
                    name: data.name,
                    description: data.description,
                    // Only an existing floor says where it is.
                    ...(target.place ? { building_id: data.building_id } : {}),
                };
            default:
                return {
                    name: data.name,
                    is_technical: data.is_technical,
                    description: data.description,
                    ...(target.place ? { floor_id: data.floor_id } : {}),
                };
        }
    });

    switch (target.kind) {
        case 'building':
            if (target.place) {
                form.put(updateBuilding(target.place.id).url, options);
            } else {
                form.post(storeBuilding(target.siteId).url, options);
            }

            break;
        case 'floor':
            if (target.place) {
                form.put(updateFloor(target.place.id).url, options);
            } else {
                form.post(storeFloor(target.building.id).url, options);
            }

            break;
        default:
            if (target.place) {
                form.put(updateRoom(target.place.id).url, options);
            } else {
                form.post(storeRoom(target.floor.id).url, options);
            }
    }
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-md" data-test="place-dialog">
            <form v-if="target" class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>

                <!-- An existing floor can be moved to another building
                     of the site, when the site has several. -->
                <div
                    v-if="
                        target.kind === 'floor' &&
                        target.place &&
                        places.length > 1
                    "
                    class="grid gap-2"
                >
                    <Label for="place-building">{{ $t('Building') }}</Label>
                    <NativeSelect
                        id="place-building"
                        v-model="form.building_id"
                        name="building_id"
                        aria-describedby="place-building-help"
                        :aria-invalid="
                            form.errors.building_id ? 'true' : undefined
                        "
                    >
                        <option
                            v-for="building in places"
                            :key="building.id"
                            :value="building.id"
                        >
                            {{ building.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.building_id" />
                    <p
                        id="place-building-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Another building of the site moves the floor there, with its rooms.',
                            )
                        }}
                    </p>
                </div>

                <!-- An existing room can be moved to another floor of the
                     site. -->
                <div
                    v-if="
                        target.kind === 'room' &&
                        target.place &&
                        floorOptions.length > 1
                    "
                    class="grid gap-2"
                >
                    <Label for="place-floor">{{ $t('Floor') }}</Label>
                    <NativeSelect
                        id="place-floor"
                        v-model="form.floor_id"
                        name="floor_id"
                        aria-describedby="place-floor-help"
                        :aria-invalid="
                            form.errors.floor_id ? 'true' : undefined
                        "
                    >
                        <option
                            v-for="floor in floorOptions"
                            :key="floor.id"
                            :value="floor.id"
                        >
                            {{ floor.path }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.floor_id" />
                    <p
                        id="place-floor-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Another floor of the site moves the room there, with the devices kept in it.',
                            )
                        }}
                    </p>
                </div>

                <div v-if="target.kind === 'floor'" class="grid gap-2">
                    <Label for="place-level">{{ $t('Level') }}</Label>
                    <Input
                        id="place-level"
                        v-model="form.level"
                        type="number"
                        name="level"
                        required
                        min="-9"
                        max="99"
                        step="1"
                        class="font-mono sm:max-w-32"
                        aria-describedby="place-level-help"
                        :aria-invalid="form.errors.level ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.level" />
                    <p
                        id="place-level-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'The order of the floors: 0 for the ground floor, -1 for a basement.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="place-name">{{ $t('Name') }}</Label>
                    <Input
                        id="place-name"
                        v-model="form.name"
                        name="name"
                        required
                        maxlength="100"
                        autocomplete="off"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div v-if="target.kind === 'building'" class="grid gap-2">
                    <Label for="place-code">{{ $t('Code') }}</Label>
                    <Input
                        id="place-code"
                        v-model="form.code"
                        name="code"
                        maxlength="10"
                        autocomplete="off"
                        class="font-mono uppercase sm:max-w-48"
                        aria-describedby="place-code-help"
                        :aria-invalid="form.errors.code ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.code" />
                    <p
                        id="place-code-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Optional. A short code for labels, such as B: letters, digits and hyphens.',
                            )
                        }}
                    </p>
                </div>

                <div v-if="target.kind === 'room'" class="grid gap-2">
                    <label class="flex items-center gap-2.5">
                        <input
                            v-model="form.is_technical"
                            type="checkbox"
                            name="is_technical"
                            class="size-4.5 shrink-0 rounded-xs accent-primary"
                            aria-describedby="place-technical-help"
                            :aria-invalid="
                                form.errors.is_technical ? 'true' : undefined
                            "
                        />
                        <span>{{ $t('Technical room') }}</span>
                    </label>
                    <InputError :message="form.errors.is_technical" />
                    <p
                        id="place-technical-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'A room that holds a rack, switches or patch panels. A device can be kept in any room.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="place-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="place-description"
                        v-model="form.description"
                        name="description"
                        maxlength="1000"
                        rows="3"
                        :aria-invalid="
                            form.errors.description ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('close')"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-place"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
