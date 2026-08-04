<script setup lang="ts">
/**
 * Пассажиры кабинета: список, добавление, правка, удаление.
 * Отсюда форма покупки подставляет ФИО и документ, чтобы не вводить их
 * заново при каждой поездке.
 */
import type { SavedPassenger, SavedPassengerPayload } from '~/composables/useSavedPassengers'
import { DOC_TYPES } from '~/composables/useApi'

definePageMeta({ middleware: 'auth' })

useSeoMeta({ title: 'Пассажиры — личный кабинет' })

const api = useApi()
const toast = useToast()
const { items, load, create, update, remove } = useSavedPassengers()

await load(true)

// Справочник гражданств меняется раз в годы — кэш useAsyncData оставляем.
const { data: citizenshipsResp } = await useAsyncData('citizenships', () =>
  api<{ success: boolean, data: { code: number, name: string }[] }>('/citizenships'))

const citizenshipOptions = computed(() =>
  (citizenshipsResp.value?.data ?? []).map(c => ({ label: c.name, value: c.code })))

const genderOptions = [
  { label: 'Мужской', value: 'М' },
  { label: 'Женский', value: 'Ж' },
]

const dialogOpen = ref(false)
const editing = ref<SavedPassenger | null>(null)
const saving = ref(false)
const fieldErrors = ref<Record<string, string>>({})

function emptyForm(): SavedPassengerPayload {
  return {
    last_name: '',
    first_name: '',
    middle_name: '',
    birth_date: '',
    gender: 'М',
    doc_type: 21,
    doc_number: '',
    citizenship: 185,
  }
}

const form = reactive<SavedPassengerPayload>(emptyForm())

function openCreate() {
  editing.value = null
  fieldErrors.value = {}
  Object.assign(form, emptyForm())
  dialogOpen.value = true
}

function openEdit(passenger: SavedPassenger) {
  editing.value = passenger
  fieldErrors.value = {}
  Object.assign(form, {
    last_name: passenger.last_name,
    first_name: passenger.first_name,
    middle_name: passenger.middle_name ?? '',
    birth_date: passenger.birth_date,
    gender: passenger.gender,
    doc_type: passenger.doc_type,
    // Серия и номер правятся одной строкой — как на форме покупки.
    doc_number: passenger.document,
    citizenship: passenger.citizenship,
  })
  dialogOpen.value = true
}

async function save() {
  fieldErrors.value = {}
  saving.value = true
  try {
    editing.value
      ? await update(editing.value.id, { ...form })
      : await create({ ...form })

    dialogOpen.value = false
    toast.add({ title: editing.value ? 'Данные пассажира обновлены' : 'Пассажир сохранён', color: 'success' })
  }
  catch (e: any) {
    fieldErrors.value = apiFieldErrors(e)
    if (!Object.keys(fieldErrors.value).length) {
      toast.add({ title: apiErrorMessage(e), color: 'error' })
    }
  }
  finally {
    saving.value = false
  }
}

const removing = ref<number | null>(null)

async function confirmRemove(passenger: SavedPassenger) {
  removing.value = passenger.id
  try {
    await remove(passenger.id)
    toast.add({ title: `${passenger.full_name} удалён из профиля`, color: 'success' })
  }
  catch (e: any) {
    toast.add({ title: apiErrorMessage(e, 'Не удалось удалить пассажира'), color: 'error' })
  }
  finally {
    removing.value = null
  }
}

function docLabel(code: number): string {
  return DOC_TYPES.find(d => d.value === code)?.label ?? 'Документ'
}

function formatBirthDate(value: string): string {
  const [y, m, d] = value.split('-')
  return d && m && y ? `${d}.${m}.${y}` : value
}

/** Подсказка по формату документа — та же, что на форме покупки. */
function docHint(code: number): string {
  if (code === 21) return 'например 4444 555555'
  if (code === 22) return 'например 75 1234567'
  if (code === 3) return 'например IV-МЮ 123456'
  return ''
}
</script>

<template>
  <UContainer class="py-8 max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-3xl font-light">Пассажиры</h1>
      <div class="flex gap-2">
        <UButton to="/lk" color="neutral" variant="outline" icon="i-lucide-arrow-left">
          К моим билетам
        </UButton>
        <UButton color="error" icon="i-lucide-plus" @click="openCreate">Добавить</UButton>
      </div>
    </div>

    <p class="text-sm text-muted">
      Сохранённые данные подставляются при покупке билета: выбираете пассажира из списка —
      ФИО, дата рождения и документ заполняются сами.
    </p>

    <UCard v-if="!items.length">
      <div class="py-6 text-center space-y-3">
        <UIcon name="i-lucide-users" class="size-10 text-dimmed" />
        <p>Пока никто не сохранён.</p>
        <p class="text-sm text-muted">
          Пассажиры добавляются здесь или прямо при покупке — галочкой
          «Сохранить пассажира в профиль».
        </p>
      </div>
    </UCard>

    <UCard v-for="passenger in items" :key="passenger.id">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div class="font-medium text-lg">{{ passenger.full_name }}</div>
          <div class="text-sm text-muted mt-1">
            {{ formatBirthDate(passenger.birth_date) }},
            {{ passenger.gender === 'Ж' ? 'женский' : 'мужской' }}
          </div>
          <div class="text-sm text-muted">
            {{ docLabel(passenger.doc_type) }}: {{ passenger.document }}
          </div>
        </div>

        <div class="flex gap-2">
          <UButton
            color="neutral" variant="outline" icon="i-lucide-pencil" size="sm"
            @click="openEdit(passenger)"
          >
            Изменить
          </UButton>
          <UButton
            color="error" variant="ghost" icon="i-lucide-trash-2" size="sm"
            :loading="removing === passenger.id"
            @click="confirmRemove(passenger)"
          >
            Удалить
          </UButton>
        </div>
      </div>
    </UCard>

    <UModal
      v-model:open="dialogOpen"
      :title="editing ? 'Изменить пассажира' : 'Новый пассажир'"
    >
      <template #body>
        <form id="passenger-form" class="grid gap-4 md:grid-cols-2" @submit.prevent="save">
          <UFormField label="Фамилия" required :error="fieldErrors.last_name">
            <UInput
              v-model="form.last_name" placeholder="ИВАНОВ" class="w-full"
              :highlight="!!fieldErrors.last_name"
            />
          </UFormField>
          <UFormField label="Имя" required :error="fieldErrors.first_name">
            <UInput
              v-model="form.first_name" placeholder="ИВАН" class="w-full"
              :highlight="!!fieldErrors.first_name"
            />
          </UFormField>
          <UFormField label="Отчество" :error="fieldErrors.middle_name">
            <UInput
              v-model="form.middle_name" placeholder="ИВАНОВИЧ" class="w-full"
              :highlight="!!fieldErrors.middle_name"
            />
          </UFormField>
          <UFormField label="Дата рождения" required :error="fieldErrors.birth_date">
            <UInput
              v-model="form.birth_date" type="date" class="w-full"
              :highlight="!!fieldErrors.birth_date"
            />
          </UFormField>
          <UFormField label="Пол">
            <USelect v-model="form.gender" :items="genderOptions" value-key="value" class="w-full" />
          </UFormField>
          <UFormField label="Гражданство" :error="fieldErrors.citizenship">
            <USelectMenu
              v-model="form.citizenship" :items="citizenshipOptions" value-key="value"
              class="w-full" :highlight="!!fieldErrors.citizenship"
            />
          </UFormField>
          <UFormField label="Документ" :error="fieldErrors.doc_type">
            <USelect v-model="form.doc_type" :items="DOC_TYPES" value-key="value" class="w-full" />
          </UFormField>
          <UFormField
            label="Серия и номер" required :hint="docHint(form.doc_type)"
            :error="fieldErrors.doc_number"
          >
            <UInput
              v-model="form.doc_number" placeholder="4444 555555" class="w-full"
              :highlight="!!fieldErrors.doc_number"
            />
          </UFormField>
        </form>
      </template>

      <template #footer>
        <div class="flex gap-3">
          <UButton type="submit" form="passenger-form" color="error" :loading="saving">
            Сохранить
          </UButton>
          <UButton color="neutral" variant="outline" :disabled="saving" @click="dialogOpen = false">
            Отмена
          </UButton>
        </div>
      </template>
    </UModal>
  </UContainer>
</template>
