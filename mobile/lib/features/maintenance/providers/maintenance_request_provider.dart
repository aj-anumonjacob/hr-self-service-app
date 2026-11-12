import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/network/api_client.dart';
import '../models/maintenance_request_model.dart';
import '../models/material_request_model.dart';
import '../repositories/maintenance_request_repository.dart';

final maintenanceRequestRepositoryProvider = Provider<MaintenanceRequestRepository>((ref) {
  return MaintenanceRequestRepository(ApiClient().dio);
});

final maintenanceRequestsListProvider = FutureProvider.family<List<MaintenanceRequestModel>, String?>((ref, status) async {
  final repo = ref.watch(maintenanceRequestRepositoryProvider);
  return repo.getRequests(status: status);
});

final maintenanceRequestDetailProvider = FutureProvider.family<MaintenanceRequestModel, int>((ref, id) async {
  final repo = ref.watch(maintenanceRequestRepositoryProvider);
  return repo.getRequestDetail(id);
});

final inventoryCatalogProvider = FutureProvider.family<List<InventoryItem>, String?>((ref, search) async {
  final repo = ref.watch(maintenanceRequestRepositoryProvider);
  return repo.getInventoryCatalog(search: search);
});
