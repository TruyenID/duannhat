import { Tabs, type TabItem } from '@/components/ui/tabs';
import { useT } from '@/i18n';

const ALL_ID = '__all__';

interface Section {
  id: string;
  name: string;
}

interface Props {
  sections: Section[];
  selected: string;
  onSelect: (id: string) => void;
}

export function MenuSectionTabs({ sections, selected, onSelect }: Props) {
  const t = useT();
  const items: TabItem[] = [
    { id: ALL_ID, label: t.menu.allSections },
    ...sections.map((s) => ({ id: s.id, label: s.name })),
  ];

  return <Tabs items={items} value={selected} onValueChange={onSelect} />;
}

export { ALL_ID as ALL_SECTION_ID };
