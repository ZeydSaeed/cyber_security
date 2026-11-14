/** A mark as the server sends it: a number, "absent", or none yet. */
export type MarkValue = number | 'absent' | null;

export type TeacherSubject = {
    uuid: string;
    name: string;
    stage: number;
    sections: { section: string; students: number; entered: number }[];
};

export type SheetStudent = {
    uuid: string;
    name: string;
    number: string | null;
    mark: MarkValue;
};

export type TeacherProps = { teacher: { name: string } };
