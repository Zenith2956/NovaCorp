insert into storage.buckets (id, name, public, file_size_limit)
values ('attachments', 'attachments', false, 52428800)
on conflict (id) do nothing;

create policy "attachments upload own request" on storage.objects
  for insert to authenticated
  with check (
    bucket_id = 'attachments'
    and exists (
      select 1 from public.requests r
      where r.id::text = (storage.foldername(name))[1]
        and r.employee_id = auth.uid()
    )
  );

create policy "attachments read visible request" on storage.objects
  for select to authenticated
  using (
    bucket_id = 'attachments'
    and exists (
      select 1 from public.requests r
      where r.id::text = (storage.foldername(name))[1]
    )
  );