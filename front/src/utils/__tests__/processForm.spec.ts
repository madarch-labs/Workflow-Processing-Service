import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import type { DictionaryItem, Process, ProcessFormData } from '@/types'
import {
  changeTypeOptions,
  definitionFromForm,
  emptyForm,
  formFromProcess,
  FORM_CHANGE_TYPES,
  isNoValueOperator,
  operatorOptions,
} from '@/utils/processForm'

type Obj = Record<string, unknown>

function process(definition: Obj): Process {
  return {
    id: 1,
    name: 't',
    object_type: 'Deals',
    is_child: false,
    is_active: true,
    priority: 0,
    run_once: false,
    version: 1,
    definition,
    created_at: '',
    updated_at: '',
  } as unknown as Process
}

function load(definition: Obj): ProcessFormData {
  return formFromProcess(process(JSON.parse(JSON.stringify(definition))))
}

function roundTrip(definition: Obj): unknown {
  return definitionFromForm(load(definition))
}

function def(parts: Obj): Obj {
  return { conditions: [], preload: [], actions: [], ...parts }
}

const demoDir = fileURLToPath(new URL('../../../../doc/_meta/demo_processes/', import.meta.url))
const demos = readdirSync(demoDir).filter((f) => f.endsWith('.json'))

describe('open and save without changes keeps the definition', () => {
  it('finds the demo processes', () => {
    expect(demos.length).toBeGreaterThanOrEqual(11)
  })

  it.each(demos)('demo process %s', (file) => {
    const definition = JSON.parse(readFileSync(demoDir + file, 'utf8')).definition as Obj
    expect(roundTrip(definition)).toEqual(definition)
  })

  const cases: Array<[string, Obj]> = [
    ['constants', def({ constants: { limit: 1000, mail: 'a@b.c' } })],
    [
      'condition with cast options',
      def({
        conditions: [
          {
            field: 'object.email1',
            operator: 'ends_with',
            value: '@GMAIL.COM',
            cast: 'string',
            cast_options: { case_sensitive: false },
            on_cast_error: 'false',
          },
        ],
      }),
    ],
    ['numeric condition value', def({ conditions: [{ field: 'object.amount', operator: 'greater_than', cast: 'int', value: 5 }] })],
    ['is_empty without value and value_type', def({ conditions: [{ field: 'object.phone', operator: 'is_empty' }] })],
    [
      'in with a list value',
      def({
        conditions: [
          { field: 'object.status', operator: 'equals', value_type: 'static', value: 'New' },
          { field: 'object.sales_stage', operator: 'in', value: ['Prospecting', 'Qualification'] },
        ],
      }),
    ],
    [
      'nested group inside a list',
      def({
        conditions: [
          { field: 'object.status', operator: 'equals', value: 'Active' },
          {
            logical_operator: 'OR',
            conditions: [
              { field: 'object.amount', operator: 'greater_or_equal', cast: 'decimal', value: '1000000' },
              { field: 'v1.rating', operator: 'equals', value: 'VIP' },
            ],
          },
        ],
      }),
    ],
    [
      'OR group',
      def({
        conditions: {
          logical_operator: 'OR',
          conditions: [{ field: 'object.status', operator: 'equals', value_type: 'static', value: 'A' }],
          groups: [],
        },
      }),
    ],
    [
      'preload with strategy and without by_field',
      def({ preload: [{ var: 'acc', source: 'object_field', module: 'Accounts', value: 'object.account_id', strategy: 'lazy' }] }),
    ],
    [
      'modify with id, on_error and retry',
      def({
        actions: [
          {
            type: 'modify',
            id: 'm1',
            target: 'object.status',
            change_type: 'set_value',
            value: 'VIP',
            save_to_crm: true,
            on_error: 'continue',
            retry: { attempts: 5 },
          },
        ],
      }),
    ],
    [
      'modify with an object value',
      def({
        actions: [
          {
            type: 'modify',
            target: 'object.open_tasks',
            change_type: 'aggregate',
            value: { function: 'count', module: 'Tasks', filter: [{ field: 'status', operator: 'equals', value: 'Open' }] },
          },
        ],
      }),
    ],
    ['modify with a numeric value', def({ actions: [{ type: 'modify', target: 'object.counter', change_type: 'increment', value: 1 }] })],
    [
      'save_object with mode and on_error, without module',
      def({ actions: [{ type: 'save_object', target: 'object', mode: 'full', on_error: 'continue' }] }),
    ],
    [
      'action order with start_process',
      def({
        actions: [
          { type: 'modify', target: 'object.a', change_type: 'set_value', value: 'A' },
          { type: 'start_process', process_id: 5 },
          { type: 'modify', target: 'object.b', change_type: 'set_value', value: 'B' },
          { type: 'start_process', process_id: 6 },
          { type: 'save_object', target: 'object' },
        ],
      }),
    ],
    [
      'start_process first',
      def({
        actions: [
          { type: 'start_process', process_id: 5 },
          { type: 'modify', target: 'object.a', change_type: 'set_value', value: 'A' },
        ],
      }),
    ],
    [
      'start_process with process_ids in place',
      def({
        actions: [
          { type: 'modify', target: 'object.a', change_type: 'set_value', value: 'A' },
          { type: 'start_process', process_ids: [5, 6] },
          { type: 'modify', target: 'object.b', change_type: 'set_value', value: 'B' },
        ],
      }),
    ],
    [
      'start_process with id and on_error',
      def({ actions: [{ type: 'start_process', id: 'sp', process_id: 5, on_error: 'continue' }] }),
    ],
    [
      'other action types mixed with modify',
      def({
        actions: [
          { type: 'send_email', id: 'mail', to: ['{{ object.email }}'], subject: 'Hi', text: 'Hello' },
          { type: 'modify', target: 'object.a', change_type: 'set_value', value: 'A' },
          {
            type: 'foreach',
            source: 'list.items',
            as: 'item',
            actions: [{ type: 'modify', target: 'item.x', change_type: 'set_value', value: '1' }],
          },
          { type: 'condition', branches: [{ conditions: [], actions: [] }] },
          { type: 'delay', mode: 'duration', duration: '2d' },
        ],
      }),
    ],
    [
      'aggregate without value',
      def({
        actions: [
          {
            type: 'modify',
            target: 'object.annual_revenue',
            change_type: 'aggregate',
            function: 'sum',
            field: 'amount',
            source: { relationship: { module: 'Accounts', id: 'object.id', link: 'opportunities' } },
          },
        ],
      }),
    ],
    [
      'date_shift with its fields',
      def({ actions: [{ type: 'modify', target: 'object.due', change_type: 'date_shift', base: 'object.date_entered', shift: '+2d', time: '18:00' }] }),
    ],
    ['clear without value', def({ actions: [{ type: 'modify', target: 'object.description', change_type: 'clear' }] })],
    [
      'condition with an expression value',
      def({
        conditions: [
          { field: 'object.amount', operator: 'greater_than', cast: 'decimal', value_type: 'expression', value: 'v1.credit_limit * 0.8' },
        ],
      }),
    ],
  ]

  it.each(cases)('%s', (_name, definition) => {
    expect(roundTrip(definition)).toEqual(definition)
  })
})

describe('edits in the form', () => {
  const modifyWithExtras = def({
    actions: [
      {
        type: 'modify',
        id: 'm1',
        target: 'object.description',
        change_type: 'template',
        value: 'Deal {{ object.name }}',
        params: { escape: 'none' },
        on_error: 'continue',
      },
    ],
  })

  it('a new value keeps the other keys', () => {
    const form = load(modifyWithExtras)
    const row = form.modifyActions[0] as { value: string }
    row.value = 'Deal {{ object.id }}'
    const actions = definitionFromForm(form).actions as Obj[]
    expect(actions[0]).toEqual({ ...(modifyWithExtras.actions as Obj[])[0], value: 'Deal {{ object.id }}' })
  })

  it('a new change_type drops kind-specific keys but keeps id and on_error', () => {
    const form = load(modifyWithExtras)
    const row = form.modifyActions[0] as { change_type: string }
    row.change_type = 'set_value'
    const action = (definitionFromForm(form).actions as Obj[])[0]
    expect(action.params).toBeUndefined()
    expect(action.id).toBe('m1')
    expect(action.on_error).toBe('continue')
    expect(action.change_type).toBe('set_value')
  })

  it('a new operator keeps cast', () => {
    const form = load(def({ conditions: [{ field: 'object.amount', operator: 'greater_than', cast: 'decimal', value: '10' }] }))
    form.conditions[0].operator = 'less_than'
    const cond = (definitionFromForm(form).conditions as unknown as Obj[])[0]
    expect(cond.cast).toBe('decimal')
    expect(cond.operator).toBe('less_than')
  })

  it('clearing the module of save_object removes the key', () => {
    const form = load(def({ actions: [{ type: 'save_object', target: 'v1', module: 'Tasks', mode: 'full' }] }))
    ;(form.modifyActions[0] as { module: string }).module = ''
    expect((definitionFromForm(form).actions as Obj[])[0]).toEqual({ type: 'save_object', target: 'v1', mode: 'full' })
  })

  it('start_process after a deleted row goes last', () => {
    const form = load(
      def({
        actions: [
          { type: 'modify', target: 'object.a', change_type: 'set_value', value: 'A' },
          { type: 'start_process', process_id: 5 },
          { type: 'modify', target: 'object.b', change_type: 'set_value', value: 'B' },
        ],
      }),
    )
    form.modifyActions.splice(0, 1)
    const actions = definitionFromForm(form).actions as Obj[]
    expect(actions.map((a) => a.type)).toEqual(['modify', 'start_process'])
    expect(actions[1].process_id).toBe(5)
  })

  it('a new start row goes last', () => {
    const form = load(
      def({
        actions: [
          { type: 'start_process', process_id: 5 },
          { type: 'modify', target: 'object.a', change_type: 'set_value', value: 'A' },
        ],
      }),
    )
    form.startActions.push({ _key: 'x', process_id: 9 })
    const actions = definitionFromForm(form).actions as Obj[]
    expect(actions.map((a) => a.process_id ?? a.type)).toEqual([5, 'modify', 9])
  })

  it('a new process gives the old definition shape', () => {
    expect(definitionFromForm(emptyForm())).toEqual({ conditions: [], preload: [], actions: [] })
  })

  it('form-only keys never reach the definition', () => {
    const form = load(
      def({
        conditions: [{ field: 'object.a', operator: 'equals', value: '1' }],
        preload: [{ var: 'v1', source: 'object_field', module: 'Accounts', by_field: 'id', value: 'object.account_id' }],
        actions: [
          { type: 'modify', target: 'object.a', change_type: 'set_value', value: 'A' },
          { type: 'start_process', process_id: 5 },
          { type: 'save_object', target: 'object' },
        ],
      }),
    )
    form.startActions.push({ _key: 'y', process_id: 7 })
    const json = JSON.stringify(definitionFromForm(form))
    expect(json).not.toContain('"_')
  })
})

// Dictionaries as the server returns them (GET /api/dictionaries/operators, /change-types); refresh when they change
const fixture = (name: string): DictionaryItem[] =>
  JSON.parse(readFileSync(fileURLToPath(new URL(`./fixtures/${name}.json`, import.meta.url)), 'utf8')) as DictionaryItem[]
const operators = fixture('operators')
const changeTypes = fixture('change-types')

describe('selects offer only what the form can save', () => {
  it('operators: no cast, list or range operators', () => {
    expect(operatorOptions(operators).map((o) => o.value)).toEqual([
      'equals',
      'not_equals',
      'contains',
      'not_contains',
      'starts_with',
      'ends_with',
      'is_empty',
      'is_not_empty',
      'is_null',
      'is_not_null',
      'changed',
      'not_changed',
      'changed_to',
      'changed_from',
    ])
  })

  it('operators: a loaded row keeps its own operator in the list', () => {
    const values = operatorOptions(operators, 'greater_than').map((o) => o.value)
    expect(values).toContain('greater_than')
    expect(values).not.toContain('less_than')
  })

  it('operators: the offline fallback without metadata is kept', () => {
    const fallback = [{ value: 'equals', label: 'equals' }]
    expect(operatorOptions(fallback)).toEqual(fallback)
  })

  it('operators without a value are recognised', () => {
    const noValue = operators.filter((o) => isNoValueOperator(o)).map((o) => o.value)
    expect(noValue).toEqual(['is_empty', 'is_not_empty', 'is_null', 'is_not_null', 'changed', 'not_changed'])
  })

  it('change types: only those with an input in the form', () => {
    expect(changeTypeOptions(changeTypes).map((c) => c.value)).toEqual(FORM_CHANGE_TYPES)
  })

  it('every form change type exists on the server', () => {
    const known = changeTypes.map((c) => c.value)
    for (const type of FORM_CHANGE_TYPES) expect(known).toContain(type)
  })
})

describe('rows built in the form match what the server accepts', () => {
  it('an operator without a value sends neither value nor value_type', () => {
    const form = emptyForm()
    form.conditions.push({ field: 'object.phone', operator: 'is_empty', value_type: 'static', value: '', _noValue: true })
    expect(definitionFromForm(form).conditions).toEqual([{ field: 'object.phone', operator: 'is_empty' }])
  })

  it('switching from is_empty to equals sends the value again', () => {
    const form = load(def({ conditions: [{ field: 'object.phone', operator: 'is_empty' }] }))
    form.conditions[0] = { ...form.conditions[0], operator: 'equals', value: '1', _noValue: false }
    // value_type stays absent like in the loaded row: the server defaults it to "static"
    expect(definitionFromForm(form).conditions).toEqual([{ field: 'object.phone', operator: 'equals', value: '1' }])
  })

  it('an empty increment / decrement step is omitted (server default 1)', () => {
    const form = emptyForm()
    for (const change_type of ['increment', 'decrement'] as const) {
      form.modifyActions.push({ _key: change_type, type: 'modify', target: 'object.n', change_type, value: '', save_to_crm: false })
    }
    for (const action of definitionFromForm(form).actions as Obj[]) expect(action).not.toHaveProperty('value')
  })

  it('a non-empty step is sent', () => {
    const form = emptyForm()
    form.modifyActions.push({ _key: 'i', type: 'modify', target: 'object.n', change_type: 'increment', value: '2', save_to_crm: false })
    expect((definitionFromForm(form).actions as Obj[])[0].value).toBe('2')
  })
})
